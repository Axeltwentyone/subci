<?php

namespace App\Http\Resources;

use App\Enums\DisputeStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Inclut le coffre des accès (déchiffré) pour son propriétaire :
 * la PWA le met en cache pour l'affichage hors ligne.
 *
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ready = $this->status !== SubscriptionStatus::Pending;

        return [
            'id' => (string) $this->id,
            'serviceId' => $this->service->slug,
            // Prix mensuel actuel du cercle (renouvellement au prix de l'hôte).
            'price' => $this->hostOffer?->price ?? $this->service->price,
            'hostName' => $this->hostOffer?->user?->shortName(),
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
            // Offre famille : invitation de l'hôte (lien à ouvrir, ou e-mail Apple invité).
            'invite' => ($type = $this->hostOffer?->inviteType()) ? [
                'type' => $type,
                'email' => $this->invite_email,
                'link' => $this->invite_link,
                'sentAt' => $this->invite_sent_at?->toIso8601String(),
                'joinedAt' => $this->invite_joined_at?->toIso8601String(),
                'problemAt' => $this->invite_problem_at?->toIso8601String(),
            ] : null,
            'dispute' => ($d = $this->disputes()->where('status', DisputeStatus::Open)->latest()->first())
                ? ['id' => (string) $d->id, 'reason' => $d->reason, 'at' => $d->created_at->toIso8601String()]
                : null,
        ];
    }
}
