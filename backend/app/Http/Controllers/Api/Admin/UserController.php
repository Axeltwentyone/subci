<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OfferStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'filter' => ['nullable', Rule::in(['members', 'hosts', 'suspended'])],
        ]);

        $users = User::query()
            ->withCount(['subscriptions as active_subs' => fn ($q) => $q->where('status', '!=', SubscriptionStatus::Expired)])
            ->withCount(['hostOffers as live_offers' => fn ($q) => $q->where('status', OfferStatus::Live)])
            ->withSum(['payments as total_paid' => fn ($q) => $q->where('type', PaymentType::Subscription)->where('status', PaymentStatus::Succeeded)->whereNull('refunded_at')], 'amount')
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $term).'%')->orWhere('referral_code', 'like', "%{$term}%")))
            ->when(($data['filter'] ?? null) === 'members', fn ($q) => $q->whereHas('subscriptions', fn ($s) => $s->where('status', '!=', SubscriptionStatus::Expired)))
            ->when(($data['filter'] ?? null) === 'hosts', fn ($q) => $q->whereHas('hostOffers', fn ($o) => $o->where('status', OfferStatus::Live)))
            ->when(($data['filter'] ?? null) === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->latest()
            ->paginate(30);

        return response()->json([
            'data' => collect($users->items())->map(fn (User $u) => Presenter::user($u) + [
                'activeSubs' => $u->active_subs,
                'liveOffers' => $u->live_offers,
                'totalPaid' => (int) $u->total_paid,
            ]),
            'meta' => ['total' => $users->total(), 'page' => $users->currentPage(), 'pages' => $users->lastPage()],
        ]);
    }

    /** Fiche complète : tout ce que la personne fait sur Sub.ci. */
    public function show(User $user): JsonResponse
    {
        $user->load([
            'subscriptions' => fn ($q) => $q->with('service', 'hostOffer.user')->latest(),
            'hostOffers' => fn ($q) => $q->with('service', 'user', 'members', 'joinRequests')->latest(),
            'payments' => fn ($q) => $q->with('service', 'joinRequest')->latest()->limit(50),
            'joinRequests' => fn ($q) => $q->with('user', 'payment', 'offer.service', 'offer.user')->latest()->limit(20),
        ]);

        return response()->json(['data' => Presenter::user($user) + $user->reliability() + [
            'payout' => ['method' => $user->payout_method?->label(), 'phone' => $user->payout_phone],
            'stats' => [
                'totalPaid' => (int) $user->payments->where('type', PaymentType::Subscription)->where('status', PaymentStatus::Succeeded)->whereNull('refunded_at')->sum('amount'),
                'totalEarned' => (int) $user->payments()->where('type', PaymentType::Earning)->sum('amount'),
                'totalWithdrawn' => (int) $user->payments()->where('type', PaymentType::Withdrawal)->where('status', PaymentStatus::Succeeded)->sum('amount'),
            ],
            'subscriptions' => $user->subscriptions->map(fn ($s) => Presenter::subscription($s)),
            'offers' => $user->hostOffers->map(fn ($o) => Presenter::offer($o)),
            'payments' => $user->payments->map(fn ($p) => Presenter::payment($p)),
            'requests' => $user->joinRequests->map(fn ($r) => Presenter::request($r)),
            'devices' => \App\Models\PushSubscription::where('user_id', $user->id)->count(),
        ]]);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:240']]);
        abort_if($user->suspended_at, 409, 'Ce compte est déjà suspendu.');
        $user->forceFill(['suspended_at' => now(), 'suspension_reason' => $data['reason']])->save();
        // Déconnecte tous ses appareils.
        $user->tokens()->delete();
        // Ses offres en ligne ne reçoivent plus de nouveaux membres.
        $user->hostOffers()->where('status', \App\Enums\OfferStatus::Live)->update(['status' => \App\Enums\OfferStatus::Paused]);
        $request->user()->log('user.suspend', $user, ['reason' => $data['reason']]);

        return $this->show($user->fresh());
    }

    public function unsuspend(Request $request, User $user): JsonResponse
    {
        abort_unless($user->suspended_at, 409, 'Ce compte n’est pas suspendu.');
        $user->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();
        $request->user()->log('user.unsuspend', $user);

        return $this->show($user->fresh());
    }
}
