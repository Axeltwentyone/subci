<?php

namespace App\Http\Resources;

use App\Models\Circle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Circle */
class CircleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $me = $request->user();
        $members = $this->members;
        $isMember = $members->contains('id', $me?->id);

        return [
            'code' => $this->code,
            'name' => $this->name,
            'serviceId' => $this->service->slug,
            'open' => $this->is_open,
            'seats' => $this->seats,
            'price' => $this->price,
            'share' => $this->share(),
            // Prix d'une place fixé par le créateur (cercle ouvert), sinon la part égale.
            'seatPrice' => $this->seat_price,
            'memberPrice' => $this->memberPrice(),
            'free' => max(0, $this->seats - $members->count()),
            'owner' => $this->owner->shortName(),
            'isOwner' => $this->user_id === $me?->id,
            'isMember' => $isMember,
            'closed' => $this->closed_at !== null,
            // Prénoms seulement, et seulement pour les membres ou un cercle ouvert / via le lien.
            'members' => $members->map(fn ($u) => [
                'id' => (string) $u->id,
                'name' => $u->shortName(),
                'owner' => $u->id === $this->user_id,
                'me' => $u->id === $me?->id,
            ])->values(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
