<?php

namespace App\Http\Controllers\Api;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Notifications\AppNotification;
use App\Services\DisputeService;
use App\Services\SubscriptionSweeper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
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

    /** Offre famille : le membre confirme qu'il a rejoint, ou signale que l'invitation ne marche pas. */
    public function invite(Request $request, Subscription $subscription, string $status): SubscriptionResource
    {
        $this->authorizeOwner($request, $subscription);
        $subscription->load('hostOffer.user', 'service', 'user');
        abort_unless($subscription->hostOffer?->inviteType() && $subscription->invite_sent_at, 409, 'Aucune invitation à confirmer pour cet abonnement.');

        if ($status === 'joined') {
            $subscription->update(['invite_joined_at' => now(), 'invite_problem_at' => null]);
        } else {
            abort_if($subscription->invite_problem_at?->gt(now()->subHour()), 429, 'Ton hôte est déjà prévenu. Il va t’envoyer une nouvelle invitation.');
            $subscription->update(['invite_problem_at' => now(), 'invite_joined_at' => null]);
            $short = Str::before($subscription->service->name, ' ');
            $email = $subscription->hostOffer->inviteType() === 'email';
            $subscription->hostOffer->user->notify(new AppNotification('host',
                $subscription->user->shortName().($email ? ' n’a pas reçu ton invitation' : ' : le lien ne marche plus'),
                $email
                    ? "Vérifie que tu as invité {$subscription->invite_email} dans ton Partage familial {$short}, puis confirme."
                    : "Crée un nouveau lien d’invitation {$short} et envoie-le depuis Gérer l’offre.",
                ['label' => 'Renvoyer', 'to' => "/host/offers/{$subscription->host_offer_id}"]));
        }

        return new SubscriptionResource($subscription->fresh()->load('service', 'hostOffer.user'));
    }

    private function authorizeOwner(Request $request, Subscription $subscription): void
    {
        abort_unless($subscription->user_id === $request->user()->id, 404);
    }
}
