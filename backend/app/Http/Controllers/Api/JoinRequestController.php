<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\JoinRequestResource;
use App\Models\JoinRequest;
use App\Services\JoinService;
use Illuminate\Http\Request;

/** Côté membre : annuler sa demande avant la réponse de l'hôte (remboursé). */
class JoinRequestController extends Controller
{
    public function cancel(Request $request, JoinRequest $joinRequest, JoinService $joins): JoinRequestResource
    {
        abort_unless($joinRequest->user_id === $request->user()->id, 404);

        return new JoinRequestResource($joins->cancel($joinRequest)->load('payment', 'offer.service', 'offer.user', 'user'));
    }
}
