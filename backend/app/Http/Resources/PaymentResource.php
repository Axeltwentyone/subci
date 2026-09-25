<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Payment */
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
            'at' => $this->created_at->toIso8601String(),
        ];
    }
}
