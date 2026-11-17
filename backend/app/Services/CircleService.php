<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PayMethod;
use App\Models\Circle;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cercles payants (« J'ai déjà l'abonnement ») : le créateur partage son compte,
 * chaque membre paie sa part + les frais de service Subci, chaque mois.
 *
 * L'argent revient au créateur dès que tout le cercle a payé le mois (pot atteint),
 * au plus tard RELEASE_DAYS jours après chaque paiement. Avant, il reste en séquestre :
 * si le cercle ferme, ce qui n'a pas été versé est rendu aux membres.
 */
class CircleService
{
    /** Délai maximal avant de verser une part au créateur, même si le pot n'est pas atteint. */
    public const RELEASE_DAYS = 7;

    /** Rappel avant l'échéance, et délai de grâce avant de libérer la place. */
    public const REMIND_DAYS = 3;

    public const GRACE_DAYS = 3;

    /** Ce que paie un membre pour un mois : sa part + les frais de service. */
    public static function total(Circle $circle): int
    {
        return $circle->memberPrice() + PaymentService::serviceFee(1);
    }

    /** Prépare le paiement d'une part (nouvelle place ou mois suivant). */
    public function prepare(User $user, Circle $circle, PayMethod $method, ?string $phone): Payment
    {
        return DB::transaction(function () use ($user, $circle, $method, $phone) {
            $locked = Circle::whereKey($circle->id)->lockForUpdate()->firstOrFail();
            if ($locked->closed_at) {
                throw ValidationException::withMessages(['circle' => 'Ce cercle est fermé.']);
            }
            if ($locked->user_id === $user->id) {
                throw ValidationException::withMessages(['circle' => 'C’est ton cercle : tu n’as pas de part à payer.']);
            }
            $isMember = $locked->hasMember($user);
            if (! $isMember && $locked->freeSeats() - $locked->reservedSeats() < 1) {
                throw ValidationException::withMessages(['circle' => 'Le cercle est complet. Demande à '.$locked->owner->shortName().' d’ajouter une place.']);
            }
            // Un paiement déjà en cours : on le reprend plutôt que d'en créer un second.
            $pending = $locked->payments()->where('user_id', $user->id)->where('status', PaymentStatus::Pending)->where('expires_at', '>', now())->exists();
            if ($pending) {
                throw ValidationException::withMessages(['circle' => 'Un paiement est déjà en cours pour ce cercle. Valide-le sur ton téléphone.']);
            }
            $paidUntil = $isMember ? $locked->members()->whereKey($user->id)->first()?->pivot->paid_until : null;
            if ($paidUntil && Carbon::parse($paidUntil)->gt(now()->addDays(self::REMIND_DAYS + 1))) {
                throw ValidationException::withMessages(['circle' => 'Ta part est déjà payée jusqu’au '.Carbon::parse($paidUntil)->translatedFormat('j M').'.']);
            }

            $fee = PaymentService::serviceFee(1);
            $locked->loadMissing('service');

            return $user->payments()->create([
                'type' => PaymentType::Subscription,
                'status' => PaymentStatus::Pending,
                'service_id' => $locked->service_id,
                'circle_id' => $locked->id,
                'label' => "Cercle {$locked->name} · {$locked->service->name}",
                'amount' => $locked->memberPrice() + $fee,
                'service_fee' => $fee,
                'months' => 1,
                'method' => $method,
                'phone' => $method === PayMethod::Card ? null : $phone,
                'expires_at' => now()->addSeconds(config('services.payments.request_ttl')),
            ]);
        });
    }

    /** Paiement confirmé (dans la transaction de PaymentService::confirm). */
    public function paid(Payment $payment): void
    {
        $circle = Circle::whereKey($payment->circle_id)->lockForUpdate()->first();
        $member = $payment->user;
        $isMember = $circle?->hasMember($member);

        // Cercle fermé ou complet entre-temps : le membre est remboursé.
        if (! $circle || $circle->closed_at || (! $isMember && $circle->freeSeats() < 1)) {
            $payment->update(['refunded_at' => now()]);
            $this->refund($payment, $payment->amount, 'cercle complet ou fermé');

            return;
        }

        $current = $isMember ? $circle->members()->whereKey($member->id)->first()?->pivot->paid_until : null;
        $from = $current && Carbon::parse($current)->isFuture() ? Carbon::parse($current) : now();
        $until = $from->copy()->addMonth();
        if ($isMember) {
            $circle->members()->updateExistingPivot($member->id, ['paid_until' => $until, 'reminded_at' => null]);
        } else {
            $circle->members()->attach($member->id, ['paid_until' => $until]);
        }
        $payment->update(['period_start' => $from, 'period_end' => $until]);

        // Part du créateur (sans commission Subci : seuls les frais de service sont pris).
        $share = $payment->amount - (int) $payment->service_fee;
        $owner = $circle->owner;
        $owner->payments()->create([
            'type' => PaymentType::Earning,
            'status' => PaymentStatus::Pending,
            'service_id' => $circle->service_id,
            'circle_id' => $circle->id,
            'source_payment_id' => $payment->id,
            'label' => "Cercle {$circle->name} · ".$member->shortName(),
            'amount' => $share,
            'gross' => $share,
            'months' => 1,
            'method' => $owner->payout_method ?? PayMethod::Wave,
            'period_start' => $from,
            'period_end' => $until,
            'available_at' => now()->addDays(self::RELEASE_DAYS),
        ]);

        $reached = $circle->potReached();
        if ($reached) {
            // Pot atteint : toutes les parts en attente arrivent dans le solde du créateur (à la prochaine minute).
            Payment::escrowed()->where('circle_id', $circle->id)->whereNull('held_at')->update(['available_at' => now()]);
        }

        $amount = AdminAlerts::fcfa($payment->amount);
        $member->notify(new AppNotification('pay', $isMember ? 'Part payée' : "Bienvenue dans {$circle->name}",
            "{$amount} payés · ta place est réglée jusqu’au ".$until->translatedFormat('j M').'.',
            ['label' => 'Voir le cercle', 'to' => "/circles/{$circle->code}"]));
        $owner->notify(new AppNotification('seat', $member->shortName().' a payé sa part',
            AdminAlerts::fcfa($share).' pour '.$circle->name.'. '.($reached ? 'Tout le monde a payé : l’argent arrive dans ton solde.' : 'Versé dans ton solde quand tout le cercle aura payé.'),
            ['label' => 'Voir', 'to' => "/circles/{$circle->code}"]));
    }

    /** Rappels avant l'échéance, puis place libérée après le délai de grâce (appelé régulièrement). */
    public function sweep(): int
    {
        $count = 0;
        $rows = DB::table('circle_members')->join('circles', 'circles.id', '=', 'circle_members.circle_id')
            ->whereNull('circles.closed_at')->whereNotNull('circle_members.paid_until')
            ->whereColumn('circle_members.user_id', '!=', 'circles.user_id')
            ->where('circle_members.paid_until', '<=', now()->addDays(self::REMIND_DAYS))
            ->select('circle_members.*')->get();

        foreach ($rows as $row) {
            $circle = Circle::with('owner', 'service')->find($row->circle_id);
            $member = User::find($row->user_id);
            if (! $circle || ! $member) {
                continue;
            }
            $until = Carbon::parse($row->paid_until);
            if ($until->lt(now()->subDays(self::GRACE_DAYS))) {
                $circle->members()->detach($member->id);
                $member->notify(new AppNotification('due', "Place libérée · {$circle->name}",
                    'Ta part n’a pas été payée. Tu peux rejoindre à nouveau s’il reste une place.', ['label' => 'Voir', 'to' => "/circles/{$circle->code}"]));
                $circle->owner->notify(new AppNotification('seat', $member->shortName().' a quitté '.$circle->name,
                    'Part non payée : sa place est libre. Pense à changer le mot de passe si besoin.', ['label' => 'Voir', 'to' => "/circles/{$circle->code}"]));
                $count++;
            } elseif (! $row->reminded_at || Carbon::parse($row->reminded_at)->lt($until->copy()->subDays(self::REMIND_DAYS + 1))) {
                DB::table('circle_members')->where('id', $row->id)->update(['reminded_at' => now()]);
                $member->notify(new AppNotification('due', "Ta part de {$circle->name}",
                    AdminAlerts::fcfa(self::total($circle)).' à payer avant le '.$until->translatedFormat('j M').' pour garder ta place.',
                    ['label' => 'Payer', 'to' => "/circles/{$circle->code}"]));
                $count++;
            }
        }

        return $count;
    }

    /** Cercle fermé : ce qui n'a pas encore été versé au créateur est rendu à chaque membre. */
    public function close(Circle $circle): int
    {
        return DB::transaction(function () use ($circle) {
            $earnings = Payment::escrowed()->where('circle_id', $circle->id)->with('source.user')->lockForUpdate()->get();
            foreach ($earnings as $earning) {
                $earning->update(['status' => PaymentStatus::Failed, 'held_at' => null]);
                if ($earning->source) {
                    $this->refund($earning->source, $earning->amount, "cercle {$circle->name} fermé");
                }
            }
            $circle->update(['closed_at' => now()]);

            return $earnings->count();
        });
    }

    /** Peut-on retirer ce membre ? Pas s'il a payé le mois en cours. */
    public static function paidUntil(Circle $circle, User $member): ?Carbon
    {
        $value = $circle->members()->whereKey($member->id)->first()?->pivot->paid_until;

        return $value ? Carbon::parse($value) : null;
    }

    private function refund(Payment $source, int $amount, string $why): void
    {
        $source->user->payments()->create([
            'type' => PaymentType::Refund,
            // Le membre choisit : crédit Subci tout de suite ou argent (RefundService).
            'status' => PaymentStatus::Pending,
            'refund_choice' => 'pending',
            'service_id' => $source->service_id,
            'circle_id' => $source->circle_id,
            'label' => "Remboursement {$why}",
            'amount' => $amount,
            'method' => $source->method,
            'phone' => $source->phone,
        ]);
        $source->user->notify(new AppNotification('pay', 'Remboursement',
            AdminAlerts::fcfa($amount)." te reviennent ({$why}) : en crédit Subci tout de suite, ou sur ton mobile money. À toi de choisir.",
            ['label' => 'Choisir', 'to' => '/home']));
    }
}
