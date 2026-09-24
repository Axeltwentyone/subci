<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\HostOffer */
class HostOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'serviceId' => $this->service->slug,
            'planLabel' => $this->plan_label,
            'seats' => $this->seats,
            'price' => $this->price,
            'mode' => $this->access_mode,
            'status' => $this->status,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'members' => $this->members->map(fn ($m) => [
                'id' => (string) $m->id,
                'name' => $m->name,
                'color' => $m->color,
                'invitePending' => $m->invite_pending,
                'joinedAt' => $m->joined_at?->toIso8601String(),
            ])->values(),
            'hasCredentials' => $this->access_email !== null,
            'email' => $this->access_email,
            'pendingInvite' => $this->members->firstWhere('invite_pending', true)?->name,
            'monthlyNet' => $this->monthlyNet($this->members->count()),
        ];
    }
}
