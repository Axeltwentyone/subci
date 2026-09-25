<?php

namespace App\Http\Controllers\Api;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Services\DisputeService;
use App\Services\SubscriptionSweeper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function index(Request $request, SubscriptionSweeper $sweeper): AnonymousResourceCollection
    {
        $sweeper->run($request->user());

        return SubscriptionResource::collection($request->user()->subscriptions()->with('service', 'hostOffer.user')->latest()->get());
    }

    public function show(Request $request, Subscription $subscription): SubscriptionResource
    {
        $this->authorizeOwner($request, $subscription);

        return new SubscriptionResource($subscription->load('service'));
    }

    public function update(Request $request, Subscription $subscription): SubscriptionResource
    {
        $this->authorizeOwner($request, $subscription);
        $data = $request->validate(['autoRenew' => ['required', 'boolean']]);
        $subscription->update(['auto_renew' => $data['autoRenew']]);

        return new SubscriptionResource($subscription->load('service'));
    }

    /** Annuler = arrêter le renouvellement ; l'accès reste jusqu'à l'échéance. */
    public function cancel(Request $request, Subscription $subscription): SubscriptionResource
    {
        $this->authorizeOwner($request, $subscription);
        $subscription->update(['auto_renew' => false]);

        return new SubscriptionResource($subscription->load('service'));
    }

    /** « Un souci ? » : gèle les gains de l'hôte pour ce membre jusqu'à résolution. */
    public function dispute(Request $request, Subscription $subscription, DisputeService $disputes): SubscriptionResource
    {
        $this->authorizeOwner($request, $subscription);
        $data = $request->validate([
            'reason' => ['required', Rule::enum(DisputeReason::class)],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        $disputes->open($subscription, DisputeReason::from($data['reason']), $data['message'] ?? null);

        return new SubscriptionResource($subscription->fresh()->load('service', 'hostOffer.user'));
    }

    /** Le membre confirme que c'est réglé. */
    public function solveDispute(Request $request, Subscription $subscription, DisputeService $disputes): SubscriptionResource
    {
        $this->authorizeOwner($request, $subscription);
        $open = $subscription->disputes()->where('status', DisputeStatus::Open)->firstOrFail();
        $disputes->solve($open);

        return new SubscriptionResource($subscription->fresh()->load('service', 'hostOffer.user'));
    }

    private function authorizeOwner(Request $request, Subscription $subscription): void
    {
        abort_unless($subscription->user_id === $request->user()->id, 404);
    }
}
