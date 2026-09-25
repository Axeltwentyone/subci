<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
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
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private PaymentGateway $gateway, private JoinService $joins, private Availability $availability) {}

    /**
     * Crée la demande de paiement et l'envoie à l'opérateur.
     * - Nouvel arrivant : le membre a choisi une offre ; la place est réservée
     *   pendant le paiement puis pendant la réponse de l'hôte.
     * - Renouvellement : même cercle, au prix actuel de l'hôte, sans validation.
     */
    public function checkout(User $user, Service $service, int $months, PayMethod $method, ?string $phone, ?int $offerId = null): Payment
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
                throw ValidationException::withMessages(['service' => 'Ton hôte arrête ce partage : ton accès reste actif jusqu’au '.$current->ends_at->translatedFormat('j M').'. Tu pourras choisir une autre offre ensuite.']);
            }
            $monthly = $offer?->price ?? $service->price;
        } else {
            if ($user->joinRequests()->pending()->whereHas('offer', fn ($q) => $q->where('service_id', $service->id))->exists()) {
                throw ValidationException::withMessages(['offerId' => 'Tu as déjà une demande en attente pour ce service.']);
            }
            $offer = match (true) {
                $offerId !== null => HostOffer::live()->withReservations()->where('service_id', $service->id)->find($offerId),
                // Musique : pas de choix, Sub.ci attribue la meilleure offre ouverte.
                ! $service->choosesOffer() => $this->availability->bestOffer($service, $user)
                    ?? throw ValidationException::withMessages(['service' => 'Plus de place libre sur ce service. Rejoins la liste d’attente.']),
                default => null,
            };
            if (! $offer) {
                throw ValidationException::withMessages(['offerId' => 'Choisis une offre pour ce service.']);
            }
            if ($offer->user_id === $user->id) {
                throw ValidationException::withMessages(['offerId' => 'Tu ne peux pas rejoindre ta propre offre.']);
            }
            if ($offer->freeSeats() < 1) {
                throw ValidationException::withMessages(['offerId' => 'Cette offre vient d’être complétée. Choisis-en une autre.']);
            }
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
     * - nouvel arrivant → une demande part chez l'hôte (accès après acceptation) ;
     * - renouvellement → prolongé depuis l'échéance actuelle, hôte crédité.
     */
    public function confirm(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === PaymentStatus::Succeeded) {
                return $payment;
            }
            $payment->update(['status' => PaymentStatus::Succeeded, 'confirmed_at' => now()]);

            if ($payment->subscription_id === null) {
                $this->joins->open($payment);

                return $payment->fresh();
            }

            $sub = Subscription::whereKey($payment->subscription_id)->lockForUpdate()->firstOrFail();
            $from = $sub->ends_at->isFuture() ? $sub->ends_at->copy() : now();
            $sub->update([
                'ends_at' => $from->copy()->addMonths($payment->months),
                'pay_method' => $payment->method,
                // Nouvelle échéance : les rappels J-3 / J-1 repartent de zéro.
                'reminded_j3_at' => null,
                'reminded_j1_at' => null,
            ]);
            $payment->update(['period_start' => $from, 'period_end' => $sub->ends_at]);

            if ($offer = $payment->hostOffer) {
                $this->joins->creditHost($offer, $payment, $payment->user);
            }
            $payment->user->notify(new AppNotification('pay', 'Renouvellement confirmé',
                number_format($payment->amount, 0, ',', ' ').' FCFA via '.$payment->method->label().' · jusqu’au '.$sub->ends_at->translatedFormat('j M'),
                ['label' => 'Voir', 'to' => "/subs/{$sub->id}"]));

            return $payment->fresh();
        });
    }
}
