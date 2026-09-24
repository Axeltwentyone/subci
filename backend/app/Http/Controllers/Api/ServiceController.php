<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Services\Availability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceController extends Controller
{
    public function __construct(private Availability $availability) {}

    /** Public ; si un jeton est fourni, les offres de l'utilisateur sont exclues. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $services = Service::active()->orderBy('position')->get();

        return ServiceResource::collection($this->availability->annotate($services, $request->user('sanctum')));
    }

    public function show(Request $request, Service $service): ServiceResource
    {
        abort_unless($service->is_active, 404);
        $this->availability->annotate(collect([$service]), $request->user('sanctum'));

        return new ServiceResource($service);
    }
}
