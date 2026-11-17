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
        $isOwner = $this->user_id === $me?->id;
        $paidUntil = fn ($u) => $u->pivot?->paid_until ? \Illuminate\Support\Carbon::parse($u->pivot->paid_until) : null;
        $mine = $isMember ? $members->firstWhere('id', $me->id) : null;
        $myUntil = $mine && ! $isOwner ? $paidUntil($mine) : null;

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
            // À payer chaque mois par un membre : sa part + les frais de service Subci.
            'serviceFee' => \App\Services\PaymentService::serviceFee(1),
            'total' => \App\Services\CircleService::total($this->resource),
            // Ma part : réglée jusqu'à quand, et faut-il payer (nouveau, échéance proche ou passée).
            'paidUntil' => $myUntil?->toIso8601String(),
            'mustPay' => ! $isOwner && $this->closed_at === null && (! $isMember || ! $myUntil || $myUntil->lte(now()->addDays(\App\Services\CircleService::REMIND_DAYS))),
            // Créateur : tout le monde a payé le mois en cours.
            'potReached' => $isOwner ? $this->potReached() : null,
            'free' => max(0, $this->seats - $members->count()),
            'owner' => $this->owner->shortName(),
            'isOwner' => $isOwner,
            'isMember' => $isMember,
            'closed' => $this->closed_at !== null,
            // Prénoms seulement, et seulement pour les membres ou un cercle ouvert / via le lien.
            'members' => $members->map(fn ($u) => [
                'id' => (string) $u->id,
                'name' => $u->shortName(),
                'owner' => $u->id === $this->user_id,
                'me' => $u->id === $me?->id,
                // Échéance de la part : visible du créateur et du membre lui-même.
                'paidUntil' => ($isOwner || $u->id === $me?->id) && $u->id !== $this->user_id ? $paidUntil($u)?->toIso8601String() : null,
            ])->values(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
