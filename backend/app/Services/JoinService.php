<?php

namespace App\Services;

use App\Enums\AccessMode;
use App\Enums\JoinStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PayMethod;
use App\Enums\SubscriptionStatus;
use App\Models\HostOffer;
use App\Models\JoinRequest;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Cycle d'une demande : le membre a payé, l'hôte accepte ou refuse.
 * Refus, absence de réponse sous 24 h ou annulation → remboursement intégral.
 */
class JoinService
{
    private const MEMBER_COLORS = ['#FFB38F', '#9FD7BE', '#C9B8F2', '#F7D774', '#9CC7F2'];

    /** Appelé quand le paiement d'un nouvel arrivant est confirmé. */
    public function open(Payment $payment): JoinRequest
    {
        $offer = $payment->hostOffer;
        $request = JoinRequest::create([
            'host_offer_id' => $offer->id,
            'user_id' => $payment->user_id,
            'payment_id' => $payment->id,
            'status' => JoinStatus::Pending,
            'expires_at' => now()->addHours(JoinRequest::TTL_HOURS),
        ]);

        $short = Str::before($offer->service->name, ' ');
        $host = $offer->user;
        $payment->user->notify(new AppNotification('pay', 'Demande envoyée à '.$host->shortName(),
            "Paiement reçu pour {$short}. Réponse sous 24 h, sinon tu es remboursé.", ['label' => 'Voir', 'to' => '/subs']));
        $host->notify(new AppNotification('host', 'Nouvelle demande',
            $payment->user->shortName()." veut rejoindre ton {$short}. Réponds sous 24 h.", ['label' => 'Répondre', 'to' => "/host/offers/{$offer->id}"]));

        return $request;
    }

    /** L'hôte accepte : le membre entre dans le cercle, l'hôte est payé. */
    public function accept(JoinRequest $request): JoinRequest
    {
        return DB::transaction(function () use ($request) {
            $request = $this->lockPending($request);
            $offer = HostOffer::with('service', 'user')->findOrFail($request->host_offer_id);
            $payment = $request->payment;
            $member = $request->user;
            $family = $offer->access_mode === AccessMode::Family;
            $from = now();

            $sub = $member->subscriptions()->create([
                'service_id' => $offer->service_id,
                'host_offer_id' => $offer->id,
                // Famille : actif quand l'hôte a envoyé l'invitation.
                'status' => $family ? SubscriptionStatus::Pending : SubscriptionStatus::Active,
                'starts_at' => $from,
                'ends_at' => $from->copy()->addMonths($payment->months),
                'auto_renew' => true,
                'pay_method' => $payment->method,
                'profile_label' => $family ? 'Invitation famille' : 'Profil '.($offer->members()->count() + 2).' · « '.($member->first_name ?? 'Moi').' »',
                'access_email' => $family ? null : $offer->access_email,
                'access_password' => $family ? null : $offer->access_password,
            ]);
            $offer->members()->create([
                'user_id' => $member->id,
                'name' => $member->shortName(),
                'color' => self::MEMBER_COLORS[$member->id % count(self::MEMBER_COLORS)],
                'invite_pending' => $family,
                'joined_at' => now(),
            ]);
            $payment->update(['subscription_id' => $sub->id, 'period_start' => $from, 'period_end' => $sub->ends_at]);
            $request->update(['status' => JoinStatus::Accepted, 'decided_at' => now()]);

            $this->creditHost($offer, $payment, $member);

            $short = Str::before($offer->service->name, ' ');
            $host = $offer->user->shortName();
            $member->notify($family
                ? new AppNotification('ok', "{$host} t’a accepté·e", "Bienvenue dans son {$short}. L’invitation famille arrive par e-mail.", ['label' => 'Voir', 'to' => "/subs/{$sub->id}"])
                : new AppNotification('ok', "{$host} t’a accepté·e", "Ton accès {$short} est prêt dans ton coffre.", ['label' => 'Voir', 'to' => "/subs/{$sub->id}"]));

            return $request->fresh();
        });
    }

    public function decline(JoinRequest $request): JoinRequest
    {
        return $this->close($request, JoinStatus::Declined);
    }

    public function cancel(JoinRequest $request): JoinRequest
    {
        return $this->close($request, JoinStatus::Cancelled);
    }

    /** Demandes sans réponse après 24 h. */
    public function expireOverdue(): int
    {
        return JoinRequest::pending()->where('expires_at', '<=', now())->get()
            ->each(fn (JoinRequest $r) => $this->close($r, JoinStatus::Expired))
            ->count();
    }

    /** Refus / expiration / annulation : la place se libère et le membre est remboursé. */
    private function close(JoinRequest $request, JoinStatus $status): JoinRequest
    {
        return DB::transaction(function () use ($request, $status) {
            $request = $this->lockPending($request);
            $request->update(['status' => $status, 'decided_at' => now()]);
            $payment = $request->payment;
            $member = $request->user;
            $offer = $request->offer()->with('service', 'user')->first();

            // TODO agrégateur : déclencher le remboursement réel vers le compte mobile money.
            $payment->update(['refunded_at' => now()]);
            $member->payments()->create([
                'type' => PaymentType::Refund,
                'status' => PaymentStatus::Succeeded,
                'service_id' => $payment->service_id,
                'host_offer_id' => $offer->id,
                'label' => 'Remboursement '.$payment->label,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'phone' => $payment->phone,
                'confirmed_at' => now(),
            ]);

            $short = Str::before($offer->service->name, ' ');
            $amount = number_format($payment->amount, 0, ',', ' ').' FCFA';
            $host = $offer->user->shortName();
            [$title, $body] = match ($status) {
                JoinStatus::Declined => ["{$host} n’a pas pu t’accepter", "Tu es remboursé de {$amount}. Choisis une autre offre {$short}."],
                JoinStatus::Expired => ["Pas de réponse de {$host}", "Tu es remboursé de {$amount}. Choisis une autre offre {$short}."],
                default => ['Demande annulée', "Tu es remboursé de {$amount}."],
            };
            $member->notify(new AppNotification('pay', $title, $body, ['label' => 'Voir les offres', 'to' => '/service/'.$offer->service->slug]));

            if ($status === JoinStatus::Cancelled) {
                $offer->user->notify(new AppNotification('host', 'Demande annulée', $member->shortName()." a annulé sa demande pour ton {$short}."));
            }

            return $request->fresh();
        });
    }

    /** Gains de l'hôte = montant payé − frais Sub.ci ; crédités à l'acceptation. */
    public function creditHost(HostOffer $offer, Payment $payment, User $member): void
    {
        $host = User::whereKey($offer->user_id)->lockForUpdate()->first();
        $net = (int) round($payment->amount * (1 - HostOffer::FEE));
        $host->increment('balance', $net);

        $short = Str::before($offer->service->name, ' ');
        $host->payments()->create([
            'type' => PaymentType::Earning,
            'status' => PaymentStatus::Succeeded,
            'service_id' => $offer->service_id,
            'host_offer_id' => $offer->id,
            'label' => "Gains {$short} · ".$member->shortName(),
            'amount' => $net,
            'months' => $payment->months,
            'method' => $host->payout_method ?? PayMethod::Wave,
            'confirmed_at' => now(),
        ]);
        $host->notify(new AppNotification('host', 'Paiement reçu', '+'.number_format($net, 0, ',', ' ')." FCFA · {$short}, {$payment->months} mois"));
    }

    private function lockPending(JoinRequest $request): JoinRequest
    {
        $locked = JoinRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== JoinStatus::Pending) {
            throw new ConflictHttpException('Cette demande a déjà été traitée.');
        }

        return $locked;
    }
}
