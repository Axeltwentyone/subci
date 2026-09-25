<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PayMethod;
use App\Models\HostOffer;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\AppNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Séquestre des gains d'hôte.
 *
 * Un paiement de N mois devient N gains, chacun disponible au début de son mois
 * + `hold_hours`. Tant qu'un gain n'est pas disponible, il peut être rendu au membre
 * (retrait du membre, litige). Un litige ouvert gèle les gains concernés.
 */
class EarningService
{
    /** Planifie les gains de l'hôte pour un paiement de membre (période démarrant à $from). */
    public function schedule(HostOffer $offer, Payment $payment, User $member, CarbonInterface $from): void
    {
        $host = $offer->user;
        $months = max(1, (int) $payment->months);
        $base = intdiv($payment->amount, $months);
        $hold = (int) config('services.payments.hold_hours');
        $short = Str::before($offer->service->name, ' ');

        for ($k = 0; $k < $months; $k++) {
            // Le dernier mois récupère l'arrondi.
            $gross = $k === $months - 1 ? $payment->amount - $base * ($months - 1) : $base;
            $host->payments()->create([
                'type' => PaymentType::Earning,
                'status' => PaymentStatus::Pending,
                'service_id' => $offer->service_id,
                'host_offer_id' => $offer->id,
                'source_payment_id' => $payment->id,
                'label' => "Gains {$short} · ".$member->shortName().($months > 1 ? ' · mois '.($k + 1)."/{$months}" : ''),
                'amount' => (int) round($gross * (1 - HostOffer::FEE)),
                'gross' => $gross,
                'months' => 1,
                'method' => $host->payout_method ?? PayMethod::Wave,
                'available_at' => $from->copy()->addMonths($k)->addHours($hold),
            ]);
        }

        $net = (int) round($payment->amount * (1 - HostOffer::FEE));
        $first = $from->copy()->addHours($hold);
        $host->notify(new AppNotification('host', 'Paiement reçu',
            '+'.self::fcfa($net)." · {$short}, {$payment->months} mois. Disponible ".($months > 1 ? 'mois par mois, dès le ' : 'le ').$first->translatedFormat('j M').'.'));
    }

    /** Verse dans le solde les gains arrivés à échéance (appelé chaque minute). */
    public function release(): int
    {
        return Payment::escrowed()->whereNull('held_at')->where('available_at', '<=', now())
            ->orderBy('available_at')->limit(200)->pluck('id')
            ->filter(fn (int $id) => $this->releaseOne($id))
            ->count();
    }

    private function releaseOne(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $earning = Payment::whereKey($id)->lockForUpdate()->first();
            if (! $earning || $earning->status !== PaymentStatus::Pending || $earning->held_at || $earning->available_at?->isFuture()) {
                return false;
            }
            $host = User::whereKey($earning->user_id)->lockForUpdate()->first();
            $host->increment('balance', $earning->amount);
            $earning->update(['status' => PaymentStatus::Succeeded, 'confirmed_at' => now()]);
            $host->notify(new AppNotification('host', 'Gains disponibles', '+'.self::fcfa($earning->amount).' dans ton solde, prêts à retirer.'));

            return true;
        });
    }

    /**
     * Rend au membre la part non encore versée à l'hôte (retrait du membre, litige).
     * Renvoie le montant remboursé et la date jusqu'à laquelle le membre a payé l'hôte.
     *
     * @return array{0: int, 1: ?CarbonInterface}
     */
    public function refundUnreleased(Subscription $sub, string $why): array
    {
        $hold = (int) config('services.payments.hold_hours');
        $earnings = Payment::escrowed()->whereIn('source_payment_id', $sub->payments()->select('id'))
            ->lockForUpdate()->orderBy('available_at')->get();
        if ($earnings->isEmpty()) {
            return [0, null];
        }

        $gross = (int) $earnings->sum('gross');
        // Accès payé jusqu'au début du premier mois rendu.
        $paidUntil = $earnings->first()->available_at->copy()->subHours($hold);
        Payment::whereIn('id', $earnings->modelKeys())->update(['status' => PaymentStatus::Failed, 'held_at' => null]);

        $source = $earnings->first()->source;
        $manual = (bool) config('services.payments.manual_payouts');
        $refund = $sub->user->payments()->create([
            'type' => PaymentType::Refund,
            'status' => $manual ? PaymentStatus::Pending : PaymentStatus::Succeeded,
            'service_id' => $sub->service_id,
            'host_offer_id' => $earnings->first()->host_offer_id,
            'subscription_id' => $sub->id,
            'label' => "Remboursement {$why}",
            'amount' => $gross,
            'method' => $source?->method ?? PayMethod::Wave,
            'phone' => $source?->phone ?? $sub->user->phone,
            'confirmed_at' => $manual ? null : now(),
        ]);
        if ($manual) {
            AdminAlerts::send('payouts', 'Remboursement à verser · '.self::fcfa($gross),
                $sub->user->shortName()." · {$why}", '/payouts', 'payouts');
        }

        return [$refund->amount, $paidUntil];
    }

    /** Gèle / dégèle les gains en séquestre liés à un abonnement. */
    public function hold(Subscription $sub, bool $on): int
    {
        return Payment::escrowed()->whereIn('source_payment_id', $sub->payments()->select('id'))
            ->update(['held_at' => $on ? now() : null]);
    }

    public static function fcfa(int $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }
}
