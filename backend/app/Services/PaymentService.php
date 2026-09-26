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
use App\Models\PaymentReference;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private PaymentGateway $gateway, private JoinService $joins, private Availability $availability, private ReferralService $referrals) {}

    /**
     * Crée la demande de paiement et l'envoie à l'opérateur.
     * - Nouvel arrivant : le membre a choisi une offre ; la place est réservée
     *   pendant le paiement puis pendant la réponse de l'hôte.
     * - Renouvellement : même cercle, au prix actuel de l'hôte, sans validation.
     */
    public function checkout(User $user, Service $service, int $months, PayMethod $method, ?string $phone, ?int $offerId = null, ?string $returnOrigin = null, ?string $inviteEmail = null): Payment
    {
        $payment = DB::transaction(function () use ($user, $service, $months, $method, $phone, $offerId, $inviteEmail) {
            $current = $user->subscriptions()
                ->where('service_id', $service->id)
                ->where('status', '!=', SubscriptionStatus::Expired)
                ->whereNotNull('host_offer_id')
                ->with('hostOffer')
                ->latest('ends_at')
                ->first();

            if ($current?->hostOffer) {
                // Renouvellement dans le même cercle.
                $offer = $current->hostOffer;
                if ($offer->status === OfferStatus::Closed) {
                    throw ValidationException::withMessages(['service' => 'Ton hôte arrête ce partage : ton accès reste actif jusqu’au '.$current->ends_at->translatedFormat('j M').'. Tu pourras choisir une autre offre ensuite.']);
                }
            } else {
                // Nouvel arrivant (ou membre retiré / sans cercle) : il faut une offre avec une place libre.
                $current = null;
                if ($user->joinRequests()->pending()->whereHas('offer', fn ($q) => $q->where('service_id', $service->id))->exists()) {
                    throw ValidationException::withMessages(['offerId' => 'Tu as déjà une demande en attente pour ce service.']);
                }
                $offerId = match (true) {
                    $offerId !== null => $offerId,
                    // Musique : pas de choix, Sub.ci attribue la meilleure offre ouverte.
                    ! $service->choosesOffer() => $this->availability->bestOffer($service, $user)?->id
                        ?? throw ValidationException::withMessages(['service' => 'Plus de place libre sur ce service. Rejoins la liste d’attente.']),
                    default => throw ValidationException::withMessages(['offerId' => 'Choisis une offre pour ce service.']),
                };
                // Verrou : deux achats simultanés ne peuvent pas prendre la même dernière place.
                $offer = HostOffer::live()->withReservations()->where('service_id', $service->id)->lockForUpdate()->find($offerId)
                    ?? throw ValidationException::withMessages(['offerId' => 'Choisis une offre pour ce service.']);
                if ($offer->user_id === $user->id) {
                    throw ValidationException::withMessages(['offerId' => 'Tu ne peux pas rejoindre ta propre offre.']);
                }
                if ($offer->freeSeats() < 1) {
                    throw ValidationException::withMessages(['offerId' => 'Cette offre vient d’être complétée. Choisis-en une autre.']);
                }
                if ($offer->inviteType() === 'email' && ! $inviteEmail) {
                    throw ValidationException::withMessages(['inviteEmail' => 'Indique l’e-mail de ton identifiant Apple : ton hôte en a besoin pour t’inviter dans sa famille.']);
                }
            }

            // Prix de l'hôte + frais de service Sub.ci (offerts au filleul), moins le crédit parrainage.
            $subtotal = Service::durationPrice($offer->price, $months);
            $fee = $this->referrals->waivesFee($user) ? 0 : (int) config('services.payments.service_fee');
            $credit = $this->referrals->reserveCredit($user, $subtotal + $fee - (int) config('services.referral.min_payable'));

            return $user->payments()->create([
                'type' => PaymentType::Subscription,
                'status' => PaymentStatus::Pending,
                'service_id' => $service->id,
                'subscription_id' => $current?->id,
                'host_offer_id' => $offer->id,
                'label' => "{$service->name} · {$months} mois",
                'amount' => $subtotal + $fee - $credit,
                'service_fee' => $fee,
                'credit_used' => $credit,
                'months' => $months,
                'method' => $method,
                'phone' => $method === PayMethod::Card ? null : $phone,
                'invite_email' => $current ? null : ($offer->inviteType() === 'email' ? $inviteEmail : null),
                'expires_at' => now()->addSeconds(config('services.payments.request_ttl')),
            ]);
        });

        $payment->return_url = rtrim($returnOrigin ?? config('app.frontend_url'), '/')."/pay/{$payment->reference}";
        $this->send($payment);
        $user->update(['last_pay_method' => $method]);

        return $payment;
    }

    /** Envoie la demande à la passerelle et garde sa référence (la place réservée est libérée si ça échoue). */
    private function send(Payment $payment): void
    {
        try {
            $request = $this->gateway->request($payment);
        } catch (\Throwable $e) {
            $payment->update(['status' => PaymentStatus::Failed]);
            $this->referrals->restoreCredit($payment);
            throw $e;
        }
        $payment->provider_reference = $request['reference'];
        $payment->checkout_url = $request['url'];
        $payment->save();
        $payment->references()->create(['reference' => $request['reference']]);
    }

    /** Renvoie la demande (« Je n'ai rien reçu ») : nouvelle référence, l'ancienne reste surveillée. */
    public function resend(Payment $payment): Payment
    {
        if ($payment->status !== PaymentStatus::Pending && $payment->status !== PaymentStatus::Expired) {
            return $payment;
        }
        $payment->status = PaymentStatus::Pending;
        $payment->expires_at = now()->addSeconds(config('services.payments.request_ttl'));
        DB::transaction(fn () => $this->referrals->reapplyCredit($payment));
        $this->send($payment);

        return $payment;
    }

    /**
     * Statut à jour d'un paiement (polling de la PWA, webhook, rattrapage). Idempotent.
     * Hors `force`, la passerelle n'est pas interrogée plus d'une fois toutes les `poll_interval` s.
     */
    public function refresh(Payment $payment, bool $force = false): Payment
    {
        if ($payment->status === PaymentStatus::Succeeded) {
            return $payment;
        }
        $interval = (int) config('services.payments.poll_interval');
        if (! $force && $interval > 0 && ! Cache::add("pay-poll:{$payment->id}", 1, $interval)) {
            return $payment;
        }
        $this->ensureReference($payment);
        // Relecture forcée (admin) : on redemande aussi les demandes marquées échouées ou expirées,
        // au cas où la passerelle les aurait finalement encaissées.
        $refs = $payment->references()->when(! $force, fn ($q) => $q->where('status', 'pending'), fn ($q) => $q->where('status', '!=', 'completed'))->get();
        foreach ($refs as $ref) {
            $this->check($ref->setRelation('payment', $payment));
            $payment->refresh();
            if ($payment->status === PaymentStatus::Succeeded) {
                break;
            }
        }

        return $payment;
    }

    /**
     * Rattrapage (chaque minute) : toutes les demandes des dernières 24 h encore sans réponse,
     * y compris celles d'un paiement annulé, expiré ou relancé : un membre qui paie quand même
     * n'est jamais perdu (et un double paiement est remboursé).
     */
    public function reconcile(int $limit = 50): int
    {
        Payment::where('status', PaymentStatus::Pending)->whereNotNull('provider_reference')->doesntHave('references')
            ->limit($limit)->get()->each(fn (Payment $p) => $this->ensureReference($p));

        return PaymentReference::with('payment')->where('status', 'pending')
            ->where('created_at', '>', now()->subDay())
            ->oldest('updated_at')->limit($limit)->get()
            ->each(fn (PaymentReference $ref) => $this->check($ref))
            ->count();
    }

    /** Webhook : référence connue → relecture immédiate (y compris remboursement après succès). */
    public function handleWebhook(string $reference): bool
    {
        $ref = PaymentReference::with('payment')->where('reference', $reference)->first();
        if (! $ref) {
            $payment = Payment::where('provider_reference', $reference)->first();
            $ref = $payment ? $this->ensureReference($payment) : null;
        }
        if (! $ref) {
            return false;
        }
        $ref->status === 'completed' ? $this->checkReversal($ref) : $this->check($ref);

        return true;
    }

    /** Lit une demande chez la passerelle et en tire les conséquences. */
    private function check(PaymentReference $ref): void
    {
        $payment = $ref->payment;
        $status = $this->gateway->status($payment, $ref->reference);
        $ref->touch();

        if ($status === PaymentStatus::Succeeded) {
            $ref->update(['status' => 'completed']);
            $fresh = $payment->fresh();
            if ($fresh->status !== PaymentStatus::Succeeded) {
                $this->confirm($fresh);
            } else {
                $this->refundDuplicate($ref, $fresh);
            }

            return;
        }

        $current = $ref->reference === $payment->provider_reference;
        if ($status === PaymentStatus::Failed || $status === PaymentStatus::Expired) {
            $ref->update(['status' => $status->value]);
            if ($current && $payment->status === PaymentStatus::Pending) {
                $payment->update(['status' => $status]);
                $this->referrals->restoreCredit($payment);
            }
        } elseif ($current && $payment->status === PaymentStatus::Pending && $payment->expires_at?->lt(now()->subMinutes(10))) {
            // Plus de réponse 10 min après l'expiration : expiré côté app, mais la demande reste surveillée 24 h.
            $payment->update(['status' => PaymentStatus::Expired]);
            $this->referrals->restoreCredit($payment);
        }
    }

    /** Le membre a validé deux demandes pour le même paiement : la seconde lui est rendue. */
    private function refundDuplicate(PaymentReference $ref, Payment $payment): void
    {
        DB::transaction(function () use ($ref, $payment) {
            $ref = PaymentReference::whereKey($ref->id)->lockForUpdate()->first();
            if ($ref->refund_payment_id) {
                return;
            }
            $manual = (bool) config('services.payments.manual_payouts');
            $refund = $payment->user->payments()->create([
                'type' => PaymentType::Refund,
                'status' => $manual ? PaymentStatus::Pending : PaymentStatus::Succeeded,
                'service_id' => $payment->service_id,
                'label' => 'Remboursement paiement en double '.$payment->reference,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'phone' => $payment->phone,
                'confirmed_at' => $manual ? null : now(),
            ]);
            $ref->update(['refund_payment_id' => $refund->id]);
            $payment->user->notify(new AppNotification('pay', 'Paiement en double',
                'Tu as payé deux fois '.$payment->label.'. '.AdminAlerts::fcfa($payment->amount).' te sont remboursés.'));
            AdminAlerts::send('payouts', 'Double paiement à rembourser · '.AdminAlerts::fcfa($payment->amount),
                $payment->user->shortName().' · '.$payment->reference, '/payouts', 'payouts');
        });
    }

    /** Paiement déjà confirmé que la passerelle déclare maintenant remboursé / échoué : alerte l'équipe. */
    private function checkReversal(PaymentReference $ref): void
    {
        if ($this->gateway->status($ref->payment, $ref->reference) === PaymentStatus::Succeeded) {
            return;
        }
        $ref->update(['status' => 'reversed']);
        Log::warning('GeniusPay : paiement confirmé puis annulé / remboursé', ['payment' => $ref->payment->reference, 'reference' => $ref->reference]);
        AdminAlerts::send('payments', 'Paiement annulé chez GeniusPay',
            $ref->payment->reference.' était confirmé : vérifie le remboursement ou la contestation.', '/payments?q='.urlencode($ref->payment->reference));
    }

    /** Paiements antérieurs à l'historique des références. */
    private function ensureReference(Payment $payment): ?PaymentReference
    {
        if (! $payment->provider_reference) {
            return null;
        }

        return $payment->references()->firstOrCreate(['reference' => $payment->provider_reference]);
    }

    /**
     * Paiement validé :
     * - nouvel arrivant → une demande part chez l'hôte (accès après acceptation) ;
     * - renouvellement → prolongé depuis l'échéance actuelle, hôte crédité.
     */
    /** Alerte l'équipe : « 💰 3 500 FCFA · Awa K. · Netflix, 1 mois via Wave ». */
    private function alertAdmins(Payment $payment): void
    {
        $payment->loadMissing('user', 'service');
        $what = ($payment->subscription_id ? 'Renouvellement ' : '').($payment->service?->name ?? $payment->label);
        AdminAlerts::send('payments',
            'Paiement reçu · '.AdminAlerts::fcfa($payment->amount),
            $payment->user->shortName()." · {$what}, {$payment->months} mois via ".$payment->method->label(),
            '/payments?q='.urlencode($payment->reference),
            'pay-'.$payment->id);
    }

    public function confirm(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === PaymentStatus::Succeeded) {
                return $payment;
            }
            $payment->update(['status' => PaymentStatus::Succeeded, 'confirmed_at' => now()]);
            // Confirmé après avoir été abandonné : le crédit parrainage rendu est repris.
            $this->referrals->reapplyCredit($payment);
            $this->alertAdmins($payment);

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
                app(EarningService::class)->schedule($offer->loadMissing('service', 'user'), $payment, $payment->user, $from);
            }
            $payment->user->notify(new AppNotification('pay', 'Renouvellement confirmé',
                number_format($payment->amount, 0, ',', ' ').' FCFA via '.$payment->method->label().' · jusqu’au '.$sub->ends_at->translatedFormat('j M'),
                ['label' => 'Voir', 'to' => "/subs/{$sub->id}"]));

            return $payment->fresh();
        });
    }
}
