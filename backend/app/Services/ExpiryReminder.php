<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Notifications\AppNotification;
use Illuminate\Support\Str;

/**
 * « Un rappel avant chaque échéance — jamais coupé. »
 * J-3 puis J-1, une seule fois chacun pour une date de fin donnée.
 */
class ExpiryReminder
{
    public function run(): int
    {
        $sent = 0;

        // J-1 d'abord : si on est déjà à moins de 24 h, inutile d'envoyer aussi le J-3.
        Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->whereBetween('ends_at', [now(), now()->addDay()])
            ->whereNull('reminded_j1_at')
            ->with('service', 'user')
            ->each(function (Subscription $sub) use (&$sent) {
                $this->notify($sub, 'expire demain');
                $sub->update(['reminded_j1_at' => now(), 'reminded_j3_at' => $sub->reminded_j3_at ?? now()]);
                $sent++;
            });

        Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->whereBetween('ends_at', [now()->addDay(), now()->addDays(3)])
            ->whereNull('reminded_j3_at')
            ->with('service', 'user')
            ->each(function (Subscription $sub) use (&$sent) {
                $days = (int) ceil(now()->diffInSeconds($sub->ends_at) / 86400);
                $this->notify($sub, "expire dans {$days} jours");
                $sub->update(['reminded_j3_at' => now()]);
                $sent++;
            });

        return $sent;
    }

    private function notify(Subscription $sub, string $when): void
    {
        $short = Str::before($sub->service->name, ' ');
        $sub->user->notify(new AppNotification(
            'due',
            "{$short} {$when}",
            'Renouvelle en 1 tap pour garder ta place.',
            ['label' => 'Renouveler', 'to' => '/checkout/'.$sub->service->slug],
        ));
    }
}
