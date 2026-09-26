<?php

namespace App\Services;

use App\Models\Service;
use App\Models\Waitlist;
use App\Notifications\AppNotification;

/**
 * Liste d'attente par service. Chaque minute, les services qui ont des places libres
 * préviennent les personnes en attente (une seule fois ; elles se réinscrivent si besoin).
 */
class WaitlistService
{
    public function __construct(private Availability $availability) {}

    public function join(int $userId, Service $service): Waitlist
    {
        return Waitlist::updateOrCreate(['user_id' => $userId, 'service_id' => $service->id], ['notified_at' => null]);
    }

    public function leave(int $userId, Service $service): void
    {
        Waitlist::where('user_id', $userId)->where('service_id', $service->id)->delete();
    }

    public function notifyAvailable(): int
    {
        $ids = Waitlist::whereNull('notified_at')->distinct()->pluck('service_id');
        if ($ids->isEmpty()) {
            return 0;
        }
        $open = $this->availability->annotate(Service::whereIn('id', $ids)->where('is_active', true)->get())
            ->filter(fn (Service $s) => ($s->avail_free ?? 0) > 0);

        $sent = 0;
        foreach ($open as $service) {
            Waitlist::with('user')->where('service_id', $service->id)->whereNull('notified_at')
                ->oldest()->limit(100)->get()
                ->each(function (Waitlist $w) use ($service, &$sent) {
                    // Pas de message à qui a déjà un abonnement en cours sur ce service.
                    if ($w->user->subscriptions()->where('service_id', $service->id)->where('ends_at', '>', now())->exists()) {
                        $w->delete();

                        return;
                    }
                    $w->update(['notified_at' => now()]);
                    $w->user->notify(new AppNotification('seat', "Une place s’est libérée sur {$service->name}",
                        'Premier arrivé, premier servi : réserve-la maintenant.', ['label' => 'Voir', 'to' => "/service/{$service->slug}"]));
                    $sent++;
                });
        }

        return $sent;
    }
}
