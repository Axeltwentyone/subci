<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\JoinStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Dispute;
use App\Models\HostOffer;
use App\Models\JoinRequest;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;

/** Formats JSON communs aux écrans d'administration. */
final class Presenter
{
    public static function user(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'shortName' => $u->shortName(),
            'phone' => $u->phone,
            'referralCode' => $u->referral_code,
            'balance' => $u->balance,
            'createdAt' => $u->created_at?->toIso8601String(),
            'suspendedAt' => $u->suspended_at?->toIso8601String(),
            'suspensionReason' => $u->suspension_reason,
            'removalsCount' => $u->removals_count,
        ];
    }

    private static function brand(Service $s): array
    {
        return ['id' => $s->slug, 'name' => $s->name, 'color' => $s->color, 'fg' => $s->fg, 'mono' => $s->mono];
    }

    public static function dispute(Dispute $d): array
    {
        $sub = $d->subscription;
        $escrow = Payment::escrowed()->whereIn('source_payment_id', $sub->payments()->select('id'));

        return [
            'id' => $d->id,
            'status' => $d->status->value,
            'reason' => $d->reason->value,
            'reasonLabel' => $d->reason->label(),
            'message' => $d->message,
            'resolution' => $d->resolution,
            'member' => ['id' => $d->user->id, 'name' => $d->user->name ?? $d->user->shortName(), 'phone' => $d->user->phone] + $d->user->reliability(),
            'host' => $d->offer?->user ? ['id' => $d->offer->user->id, 'name' => $d->offer->user->name ?? $d->offer->user->shortName(), 'phone' => $d->offer->user->phone, 'removalsCount' => $d->offer->user->removals_count] : null,
            'offerId' => $d->host_offer_id,
            'service' => self::brand($sub->service),
            'subscription' => ['id' => $sub->id, 'startsAt' => $sub->starts_at?->toIso8601String(), 'endsAt' => $sub->ends_at?->toIso8601String()],
            // Ce qui peut encore être rendu au membre (pas encore versé à l'hôte).
            'refundable' => (int) (clone $escrow)->sum('gross'),
            'hostDisputes' => $d->host_offer_id ? Dispute::whereIn('host_offer_id', HostOffer::where('user_id', $d->offer?->user_id)->select('id'))->count() : 0,
            'resolvedAt' => $d->resolved_at?->toIso8601String(),
            'createdAt' => $d->created_at->toIso8601String(),
        ];
    }

    /** Où va l'argent d'un paiement de membre : commission Sub.ci + versements à l'hôte (mois par mois). */
    public static function split(Payment $p): ?array
    {
        if ($p->type !== PaymentType::Subscription || $p->status !== PaymentStatus::Succeeded) {
            return null;
        }
        $earnings = Payment::with('user')->where('source_payment_id', $p->id)->orderBy('available_at')->get();
        $refunds = Payment::where('type', PaymentType::Refund)->where('user_id', $p->user_id)
            ->where(fn ($q) => $q->where('subscription_id', $p->subscription_id ?? 0)->orWhere('label', 'like', '%'.$p->reference.'%'))
            ->where('created_at', '>=', $p->created_at)->get();

        return [
            'host' => $earnings->first()?->user ? ['id' => $earnings->first()->user->id, 'name' => $earnings->first()->user->shortName()] : null,
            'commission' => (int) ($earnings->sum('gross') - $earnings->sum('amount')),
            'installments' => $earnings->map(fn (Payment $e) => [
                'id' => $e->id,
                'label' => $e->label,
                'amount' => $e->amount,
                'gross' => $e->gross,
                'status' => $e->status->value,
                'periodStart' => $e->period_start?->toIso8601String(),
                'availableAt' => $e->available_at?->toIso8601String(),
                'heldAt' => $e->held_at?->toIso8601String(),
            ])->values(),
            'refunded' => (int) $refunds->sum('amount'),
        ];
    }

    public static function payment(Payment $p): array
    {
        return [
            'id' => $p->id,
            'ref' => $p->reference,
            'providerRef' => $p->provider_reference,
            'type' => $p->type->value,
            'direction' => $p->type->direction(),
            'status' => $p->status->value,
            'label' => $p->label,
            'amount' => $p->amount,
            'months' => $p->months,
            'method' => $p->method->value,
            'methodLabel' => $p->method->label(),
            'phone' => $p->phone,
            'service' => $p->service ? ['id' => $p->service->slug, 'name' => $p->service->name, 'color' => $p->service->color, 'fg' => $p->service->fg, 'mono' => $p->service->mono] : null,
            'user' => $p->relationLoaded('user') && $p->user ? ['id' => $p->user->id, 'name' => $p->user->shortName(), 'phone' => $p->user->phone] : null,
            'joinStatus' => $p->relationLoaded('joinRequest') ? $p->joinRequest?->status->value : null,
            // Gain d'hôte : mois couvert, date de versement au solde, gel éventuel, paiement du membre d'origine.
            'gross' => $p->gross,
            'periodStart' => $p->period_start?->toIso8601String(),
            'availableAt' => $p->available_at?->toIso8601String(),
            'heldAt' => $p->held_at?->toIso8601String(),
            'source' => $p->source_payment_id && $p->relationLoaded('source') && $p->source
                ? ['id' => $p->source->id, 'ref' => $p->source->reference, 'user' => $p->source->user?->shortName()]
                : null,
            'refundedAt' => $p->refunded_at?->toIso8601String(),
            'confirmedAt' => $p->confirmed_at?->toIso8601String(),
            'createdAt' => $p->created_at->toIso8601String(),
        ];
    }

    public static function offer(HostOffer $o): array
    {
        return [
            'id' => $o->id,
            'service' => ['id' => $o->service->slug, 'name' => $o->service->name, 'color' => $o->service->color, 'fg' => $o->service->fg, 'mono' => $o->service->mono],
            'host' => self::user($o->user) + $o->user->reliability(),
            'plan' => $o->plan_label,
            'quality' => $o->quality,
            'devices' => $o->devices ?? [],
            'mode' => $o->access_mode->value,
            'seats' => $o->seats,
            'price' => $o->price,
            'members' => $o->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'color' => $m->color, 'userId' => $m->user_id, 'invitePending' => $m->invite_pending, 'joinedAt' => $m->joined_at?->toIso8601String()])->values(),
            'pendingRequests' => $o->joinRequests->where('status', JoinStatus::Pending)->count(),
            'status' => $o->status->value,
            'hasProof' => (bool) $o->proof_path,
            'rejectionReason' => $o->rejection_reason,
            'approvedAt' => $o->approved_at?->toIso8601String(),
            'createdAt' => $o->created_at->toIso8601String(),
            'monthlyNet' => $o->monthlyNet($o->members->count()),
        ];
    }

    public static function subscription(Subscription $s): array
    {
        return [
            'id' => $s->id,
            'service' => ['id' => $s->service->slug, 'name' => $s->service->name, 'color' => $s->service->color, 'fg' => $s->service->fg, 'mono' => $s->service->mono],
            'status' => $s->displayStatus(),
            'startsAt' => $s->starts_at->toIso8601String(),
            'endsAt' => $s->ends_at->toIso8601String(),
            'autoRenew' => $s->auto_renew,
            'host' => $s->hostOffer?->user ? ['id' => $s->hostOffer->user->id, 'name' => $s->hostOffer->user->shortName()] : null,
            'offerId' => $s->host_offer_id,
        ];
    }

    public static function request(JoinRequest $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status->value,
            'member' => ['id' => $r->user->id, 'name' => $r->user->shortName(), 'phone' => $r->user->phone] + $r->user->reliability(),
            'host' => ['id' => $r->offer->user->id, 'name' => $r->offer->user->shortName()],
            'offerId' => $r->host_offer_id,
            'service' => ['id' => $r->offer->service->slug, 'name' => $r->offer->service->name, 'color' => $r->offer->service->color, 'fg' => $r->offer->service->fg, 'mono' => $r->offer->service->mono],
            'plan' => $r->offer->plan_label,
            'amount' => $r->payment->amount,
            'months' => $r->payment->months,
            'paymentRef' => $r->payment->reference,
            'expiresAt' => $r->expires_at->toIso8601String(),
            'decidedAt' => $r->decided_at?->toIso8601String(),
            'createdAt' => $r->created_at->toIso8601String(),
        ];
    }
}
