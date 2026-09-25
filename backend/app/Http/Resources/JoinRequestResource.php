<?php

namespace App\Http\Resources;

use App\Models\JoinRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Demande vue par le membre (onglet Mes abos) ou par l'hôte (Gérer l'offre).
 *
 * @mixin JoinRequest
 */
class JoinRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $forHost = $this->offer->user_id === $request->user()?->id;

        return [
            'id' => (string) $this->id,
            'offerId' => (string) $this->host_offer_id,
            'serviceId' => $this->offer->service->slug,
            'status' => $this->status,
            // Ce qui revient au cercle (sans les frais de service Sub.ci).
            'amount' => $this->payment->offerAmount(),
            'months' => $this->payment->months,
            'expiresAt' => $this->expires_at->toIso8601String(),
            'at' => $this->created_at->toIso8601String(),
            // Côté hôte : qui demande et sa fiabilité. Côté membre : quel hôte et quelle formule.
            'member' => $forHost ? ['name' => $this->user->shortName()] + $this->user->reliability() : null,
            'host' => $forHost ? null : ['name' => $this->offer->user->shortName(), 'plan' => $this->offer->plan_label],
        ];
    }
}
