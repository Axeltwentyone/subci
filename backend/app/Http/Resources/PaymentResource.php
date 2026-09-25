<?php

namespace App\Http\Resources;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'ref' => $this->reference,
            'type' => $this->type,
            'direction' => $this->type->direction(),
            'status' => $this->status,
            'label' => $this->label,
            'amount' => $this->amount,
            'serviceFee' => (int) $this->service_fee,
            'months' => $this->months,
            'method' => $this->method,
            'phone' => $this->phone,
            'serviceId' => $this->service?->slug,
            'hostName' => $this->hostOffer?->user?->shortName(),
            'joinStatus' => $this->joinRequest?->status,
            'subscriptionId' => $this->subscription_id ? (string) $this->subscription_id : null,
            'periodStart' => $this->period_start?->toIso8601String(),
            'periodEnd' => $this->period_end?->toIso8601String(),
            'expiresAt' => $this->expires_at?->toIso8601String(),
            // Page de la passerelle où valider (uniquement tant que c'est en attente).
            'checkoutUrl' => $this->status === PaymentStatus::Pending ? $this->checkout_url : null,
            // Un gain d'hôte date du jour où il arrive dans le solde, pas du paiement du membre.
            'at' => ($this->type === PaymentType::Earning ? ($this->confirmed_at ?? $this->available_at ?? $this->created_at) : $this->created_at)->toIso8601String(),
        ];
    }
}
