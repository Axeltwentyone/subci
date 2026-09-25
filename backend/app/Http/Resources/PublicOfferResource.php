<?php

namespace App\Http\Resources;

use App\Models\HostOffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Offre telle que la voit un futur membre : formule, appareils, prix, places,
 * et l'hôte (prénom + initiale, ancienneté). Jamais les identifiants.
 *
 * @mixin HostOffer
 */
class PublicOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'serviceId' => $this->service->slug,
            'plan' => $this->plan_label,
            'quality' => $this->quality,
            'devices' => $this->devices ?? [],
            'mode' => $this->access_mode,
            'invite' => $this->inviteType(),
            'price' => $this->price,
            'seats' => $this->seats,
            'free' => $this->freeSeats(),
            'members' => $this->members->map(fn ($m) => ['name' => $m->name, 'color' => $m->color])->values(),
            'host' => [
                'name' => $this->user->shortName(),
                'since' => ($this->approved_at ?? $this->created_at)->toIso8601String(),
                'trusted' => $this->user->isTrustedHost(),
            ],
        ];
    }
}
