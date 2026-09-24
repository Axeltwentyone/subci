<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\AccessMode;
use App\Enums\OfferStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PayMethod;
use App\Enums\SubscriptionStatus;
use App\Models\HostOffer;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private PaymentGateway $gateway, private Availability $availability) {}

    /**
     * Crée la demande de paiement et l'envoie à l'opérateur.
     * - Nouvel arrivant : Sub.ci réserve une place dans la meilleure offre en ligne.
     * - Renouvellement : même groupe, au prix actuel de l'hôte.
     */
    public function checkout(User $user, Service $service, int $months, PayMethod $method, ?string $phone): Payment
    {
        $current = $user->subscriptions()
            ->where('service_id', $service->id)
            ->where('status', '!=', SubscriptionStatus::Expired)
            ->with('hostOffer')
            ->latest('ends_at')
            ->first();

        if ($current) {
            $offer = $current->hostOffer;
            if ($offer?->status === OfferStatus::Closed) {
                throw ValidationException::withMessages(['service' => 'Ton hôte arrête ce partage : ton accès reste actif jusqu’au '.$current->ends_at->translatedFormat('j M').'. Tu pourras rejoindre un autre groupe ensuite.']);
            }
            $monthly = $offer?->price ?? $service->price;
        } else {
            $offer = $this->availability->bestOffer($service, $user)
                ?? throw ValidationException::withMessages(['service' => 'Plus de place libre sur ce service. Rejoins la liste d’attente.']);
            $monthly = $offer->price;
        }

        $payment = $user->payments()->create([
            'type' => PaymentType::Subscription,
            'status' => PaymentStatus::Pending,
            'service_id' => $service->id,
            'subscription_id' => $current?->id,
            'host_offer_id' => $offer?->id,
            'label' => "{$service->name} · {$months} mois",
            'amount' => Service::durationPrice($monthly, $months),
            'months' => $months,
            'method' => $method,
            'phone' => $method === PayMethod::Card ? null : $phone,
            'expires_at' => now()->addSeconds(config('services.payments.request_ttl')),
        ]);

        $payment->update(['provider_reference' => $this->gateway->request($payment)]);
        $user->update(['last_pay_method' => $method]);

        return $payment;
    }

    /** Renvoie la demande (« Je n'ai rien reçu ») : nouveau délai d'expiration. */
    public function resend(Payment $payment): Payment
    {
        if ($payment->status !== PaymentStatus::Pending && $payment->status !== PaymentStatus::Expired) {
            return $payment;
        }
        $payment->status = PaymentStatus::Pending;
        $payment->expires_at = now()->addSeconds(config('services.payments.request_ttl'));
        $payment->provider_reference = $this->gateway->request($payment);
        $payment->save();

        return $payment;
    }

    /** Interroge l'opérateur et applique le résultat (idempotent). */
    public function refresh(Payment $payment): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return $payment;
        }
        if ($payment->expires_at?->isPast()) {
            $payment->update(['status' => PaymentStatus::Expired]);

            return $payment;
        }

        return match ($this->gateway->status($payment)) {
            PaymentStatus::Succeeded => $this->confirm($payment),
            PaymentStatus::Failed => tap($payment)->update(['status' => PaymentStatus::Failed]),
            default => $payment,
        };
    }

    /**
     * Paiement validé :
     * - nouvel arrivant → rejoint l'offre réservée, reçoit les accès de l'hôte ;
     * - renouvellement → prolonge depuis l'échéance actuelle ;
     * - l'hôte est crédité du montant moins les frais Sub.ci et notifié.
     */
    public function confirm(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === PaymentStatus::Succeeded) {
                return $payment;
            }

            $member = $payment->user;
            $service = $payment->service;
            $offer = $payment->hostOffer;
            $short = Str::before($service->name, ' ');
            $isNew = $payment->subscription_id === null;

            if (! $isNew) {
                $sub = Subscription::whereKey($payment->subscription_id)->lockForUpdate()->firstOrFail();
                $from = $sub->ends_at->isFuture() ? $sub->ends_at->copy() : now();
                $sub->update(['ends_at' => $from->copy()->addMonths($payment->months), 'pay_method' => $payment->method]);
            } else {
                $from = now();
                $family = $offer?->access_mode === AccessMode::Family;
                $sub = $member->subscriptions()->create([
                    'service_id' => $service->id,
                    'host_offer_id' => $offer?->id,
                    // Famille : actif quand l'hôte a envoyé l'invitation.
                    'status' => $family ? SubscriptionStatus::Pending : SubscriptionStatus::Active,
                    'starts_at' => $from,
                    'ends_at' => $from->copy()->addMonths($payment->months),
                    'auto_renew' => true,
                    'pay_method' => $payment->method,
                    'profile_label' => $family ? 'Invitation famille' : 'Profil '.(($offer?->members()->count() ?? 0) + 2).' · « '.($member->first_name ?? 'Moi').' »',
                    'access_email' => $family ? null : $offer?->access_email,
                    'access_password' => $family ? null : $offer?->access_password,
                ]);
                $offer?->members()->create([
                    'user_id' => $member->id,
                    'name' => trim(($member->first_name ?? 'Membre').' '.mb_substr((string) $member->last_name, 0, 1).($member->last_name ? '.' : '')),
                    'color' => self::MEMBER_COLORS[$member->id % count(self::MEMBER_COLORS)],
                    'invite_pending' => $family,
                    'joined_at' => now(),
                ]);
            }

            $payment->update([
                'status' => PaymentStatus::Succeeded,
                'confirmed_at' => now(),
                'subscription_id' => $sub->id,
                'period_start' => $from,
                'period_end' => $sub->ends_at,
            ]);

            if ($offer) {
                $this->creditHost($offer, $payment, $member, $isNew);
            }

            if ($sub->status === SubscriptionStatus::Pending) {
                $member->notify(new AppNotification('ok', "Bienvenue dans {$short}", 'Ton hôte t’envoie l’invitation famille. On te prévient dès que c’est actif.', ['label' => 'Voir', 'to' => "/subs/{$sub->id}"]));
            } else {
                $member->notify(new AppNotification('ok', "{$short} est activé", 'Tes identifiants sont disponibles.', ['label' => 'Voir', 'to' => "/subs/{$sub->id}"]));
            }
            $member->notify(new AppNotification('pay', 'Paiement confirmé', number_format($payment->amount, 0, ',', ' ').' FCFA via '.$payment->method->label()));

            return $payment->fresh();
        });
    }

    /** Gains de l'hôte = montant payé − frais Sub.ci ; solde crédité immédiatement. */
    private function creditHost(HostOffer $offer, Payment $payment, User $member, bool $isNew): void
    {
        $host = User::whereKey($offer->user_id)->lockForUpdate()->first();
        $net = (int) round($payment->amount * (1 - HostOffer::FEE));
        $host->increment('balance', $net);

        $short = Str::before($offer->service->name, ' ');
        $who = $member->first_name ?? 'Un membre';
        $host->payments()->create([
            'type' => PaymentType::Earning,
            'status' => PaymentStatus::Succeeded,
            'service_id' => $offer->service_id,
            'host_offer_id' => $offer->id,
            'label' => "Gains {$short} · {$who}",
            'amount' => $net,
            'months' => $payment->months,
            'method' => $host->payout_method ?? PayMethod::Wave,
            'confirmed_at' => now(),
        ]);

        if ($isNew) {
            $body = $offer->access_mode === AccessMode::Family
                ? "{$who} a rejoint ton {$short}. Envoie-lui l’invitation famille."
                : "{$who} a rejoint ton {$short}.";
            $host->notify(new AppNotification('host', 'Nouveau membre', $body, ['label' => 'Gérer', 'to' => "/host/offers/{$offer->id}"]));
        }
        $host->notify(new AppNotification('host', 'Paiement reçu', '+'.number_format($net, 0, ',', ' ')." FCFA · {$short}, {$payment->months} mois"));
    }

    private const MEMBER_COLORS = ['#FFB38F', '#9FD7BE', '#C9B8F2', '#F7D774', '#9CC7F2'];
}
