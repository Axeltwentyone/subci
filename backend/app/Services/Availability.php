<?php

namespace App\Services;

use App\Models\HostOffer;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Places disponibles, calculées à partir des offres en ligne des hôtes.
 * Le catalogue reste par service : on n'expose ni les offres ni les hôtes.
 */
class Availability
{
    /** Offres en ligne avec au moins une place, hors offres de l'utilisateur, meilleure d'abord. */
    public function openOffers(Service $service, ?User $viewer = null): Collection
    {
        return $service->hostOffers()
            ->live()
            ->withReservations()
            ->when($viewer, fn ($q) => $q->where('user_id', '!=', $viewer->id))
            ->orderBy('price')
            ->orderBy('approved_at')
            ->get()
            ->filter(fn (HostOffer $o) => $o->freeSeats() > 0)
            ->values();
    }

    /** L'offre que Sub.ci attribue : la moins chère, puis la plus ancienne. */
    public function bestOffer(Service $service, ?User $viewer = null): ?HostOffer
    {
        return $this->openOffers($service, $viewer)->first();
    }

    /**
     * Ajoute à chaque service : places libres totales, prix d'entrée et
     * taille du groupe qui sera attribué (lus par ServiceResource).
     *
     * @param  Collection<int, Service>  $services
     */
    public function annotate(Collection $services, ?User $viewer = null): Collection
    {
        $offers = HostOffer::query()
            ->live()
            ->withReservations()
            ->whereIn('service_id', $services->pluck('id'))
            ->when($viewer, fn ($q) => $q->where('user_id', '!=', $viewer->id))
            ->orderBy('price')
            ->orderBy('approved_at')
            ->get()
            ->filter(fn (HostOffer $o) => $o->freeSeats() > 0)
            ->groupBy('service_id');

        return $services->each(function (Service $service) use ($offers) {
            $open = $offers->get($service->id, collect());
            $best = $open->first();
            $service->setAttribute('avail_free', $open->sum(fn (HostOffer $o) => $o->freeSeats()));
            $service->setAttribute('avail_price', $best?->price ?? $service->price);
            $service->setAttribute('avail_seats', $best?->seats ?? $service->seats);
            $service->setAttribute('avail_group_free', $best?->freeSeats() ?? 0);
        });
    }
}
