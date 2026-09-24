<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Services\SubscriptionSweeper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionController extends Controller
{
    public function index(Request $request, SubscriptionSweeper $sweeper): AnonymousResourceCollection
    {
        $sweeper->run($request->user());

        return SubscriptionResource::collection($request->user()->subscriptions()->with('service')->latest()->get());
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

    private function authorizeOwner(Request $request, Subscription $subscription): void
    {
        abort_unless($subscription->user_id === $request->user()->id, 404);
    }
}
