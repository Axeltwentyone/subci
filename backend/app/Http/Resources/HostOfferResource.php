<?php

namespace App\Http\Resources;

use App\Enums\JoinStatus;
use App\Models\HostOffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin HostOffer */
class HostOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Abonnements des membres (famille uniquement) : e-mail à inviter, date d'envoi.
        $familySubs = $this->inviteType()
            ? $this->subscriptions()->whereIn('user_id', $this->members->pluck('user_id')->filter())->latest('ends_at')->get()->unique('user_id')->keyBy('user_id')
            : collect();

        return [
            'id' => (string) $this->id,
            'serviceId' => $this->service->slug,
            'planLabel' => $this->plan_label,
            'plan' => $this->plan,
            'devices' => $this->devices ?? [],
            'quality' => $this->quality,
            // Limites de la formule pour l'écran « Gérer l'offre ».
            'maxSeats' => $this->planConfig()['max'],
            'allowedDevices' => $this->planConfig()['devices'],
            'reco' => $this->planConfig()['reco'],
            'requests' => JoinRequestResource::collection($this->whenLoaded('joinRequests', fn () => $this->joinRequests->where('status', JoinStatus::Pending)->values())),
            'seats' => $this->seats,
            'price' => $this->price,
            'mode' => $this->access_mode,
            'status' => $this->status,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            // Offre famille : comment inviter (« link » / « email ») et, par membre accepté, où en est l'invitation.
            'invite' => $this->inviteType(),
            'members' => $this->members->map(fn ($m) => [
                'id' => (string) $m->id,
                'name' => $m->name,
                'color' => $m->color,
                'invitePending' => $m->invite_pending,
                'inviteEmail' => $familySubs->get($m->user_id)?->invite_email,
                'inviteSentAt' => $familySubs->get($m->user_id)?->invite_sent_at?->toIso8601String(),
                'inviteJoinedAt' => $familySubs->get($m->user_id)?->invite_joined_at?->toIso8601String(),
                'inviteProblemAt' => $familySubs->get($m->user_id)?->invite_problem_at?->toIso8601String(),
                'joinedAt' => $m->joined_at?->toIso8601String(),
            ])->values(),
            'hasCredentials' => $this->access_email !== null,
            'email' => $this->access_email,
            'pendingInvite' => $this->members->firstWhere('invite_pending', true)?->name,
            'monthlyNet' => $this->monthlyNet($this->members->count()),
        ];
    }
}
