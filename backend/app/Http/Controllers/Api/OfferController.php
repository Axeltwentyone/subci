<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicOfferResource;
use App\Models\Service;
use App\Services\Availability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Les offres d'un service, parmi lesquelles le membre choisit (téléphone, ordinateur…). */
class OfferController extends Controller
{
    public function index(Request $request, Service $service, Availability $availability): AnonymousResourceCollection
    {
        abort_unless($service->is_active, 404);
        $offers = $availability->openOffers($service, $request->user('sanctum'))->load('service', 'members', 'user');

        return PublicOfferResource::collection($offers);
    }
}
