<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\JoinRequestResource;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\UserResource;
use App\Models\Service;
use App\Models\Waitlist;
use App\Services\Availability;
use App\Services\JoinService;
use App\Services\SubscriptionSweeper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tout l'état de l'app en un appel : démarrage, pull-to-refresh, retour en ligne. */
class BootstrapController extends Controller
{
    public function __invoke(Request $request, SubscriptionSweeper $sweeper, Availability $availability, JoinService $joins): JsonResponse
    {
        $user = $request->user();
        $sweeper->run($user);
        $sweeper->approveOffers($user);
        $joins->expireOverdue();

        return response()->json([
            'user' => new UserResource($user),
            'services' => ServiceResource::collection($availability->annotate(Service::active()->orderBy('position')->get(), $user)),
            'subscriptions' => SubscriptionResource::collection($user->subscriptions()->with('service', 'hostOffer.user')->latest()->get()),
            'payments' => PaymentResource::collection($user->payments()->with('service', 'hostOffer.user', 'joinRequest')->visibleInHistory()->latest('updated_at')->limit(100)->get()),
            'notifications' => NotificationResource::collection($user->notifications()->whereNull('archived_at')->latest()->limit(100)->get()),
            'host' => HostController::summary($user),
            // Demandes du membre en attente de réponse d'un hôte.
            'requests' => JoinRequestResource::collection($user->joinRequests()->pending()->with('payment', 'offer.service', 'offer.user', 'user')->latest()->get()),
            // Frais de service Sub.ci ajoutés à chaque paiement (affichés au checkout).
            'config' => ['serviceFee' => (int) config('services.payments.service_fee')],
            // Services sur lesquels le membre attend une place.
            'waitlist' => Waitlist::where('user_id', $user->id)->whereNull('notified_at')->with('service:id,slug')->get()->pluck('service.slug')->values(),
        ]);
    }
}
