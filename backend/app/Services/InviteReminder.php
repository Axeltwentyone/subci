<?php

namespace App\Services;

use App\Models\Subscription;
use App\Notifications\AppNotification;
use Illuminate\Support\Str;

/**
 * Lien d'invitation famille pas encore utilisé : les liens Spotify expirent après environ 7 jours.
 * Au bout de `invite_reminder_days`, on prévient le membre et l'hôte (une seule fois par lien).
 */
class InviteReminder
{
    public function run(): int
    {
        $days = (int) config('services.offers.invite_reminder_days');

        return Subscription::with('user', 'service', 'hostOffer.user')
            ->whereNotNull('host_offer_id')->whereNotNull('invite_link')
            ->whereNull('invite_joined_at')->whereNull('invite_reminded_at')
            ->where('invite_sent_at', '<=', now()->subDays($days))
            ->where('ends_at', '>', now())
            ->limit(200)->get()
            ->each(function (Subscription $sub) {
                $short = Str::before($sub->service->name, ' ');
                $sub->update(['invite_reminded_at' => now()]);
                $sub->user->notify(new AppNotification('due', "Ton lien {$short} expire bientôt",
                    'Rejoins la famille maintenant, puis touche « J’ai rejoint ». Le lien ne marche plus ? Dis-le à ton hôte depuis l’app.',
                    ['label' => 'Rejoindre', 'to' => "/subs/{$sub->id}"]));
                $sub->hostOffer?->user->notify(new AppNotification('host', $sub->user->shortName()." n’a pas encore rejoint {$short}",
                    'Ton lien d’invitation expire au bout de 7 jours. S’il ne marche plus, envoie-lui un nouveau lien.',
                    ['label' => 'Voir', 'to' => "/host/offers/{$sub->host_offer_id}"]));
            })
            ->count();
    }
}
