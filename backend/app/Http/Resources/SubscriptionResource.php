<?php

namespace App\Http\Resources;

use App\Enums\SubscriptionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Inclut le coffre des accès (déchiffré) pour son propriétaire :
 * la PWA le met en cache pour l'affichage hors ligne.
 *
 * @mixin \App\Models\Subscription
 */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ready = $this->status !== SubscriptionStatus::Pending;

        return [
            'id' => (string) $this->id,
            'serviceId' => $this->service->slug,
            'state' => $this->status,
            'status' => $this->displayStatus(),
            'startAt' => $this->starts_at->toIso8601String(),
            'endAt' => $this->ends_at->toIso8601String(),
            'activatesAt' => $this->activates_at?->toIso8601String(),
            'autoRenew' => $this->auto_renew,
            'method' => $this->pay_method,
            'profile' => $this->profile_label,
            'email' => $ready ? $this->access_email : null,
            'password' => $ready ? $this->access_password : null,
            'pin' => $ready ? $this->access_pin : null,
        ];
    }
}
