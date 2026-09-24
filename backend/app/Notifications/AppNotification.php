<?php

namespace App\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notification de l'onglet « Activité ». Chaque notif porte son action
 * (ex. « Renouveler ») pour que l'utilisateur n'ait pas à l'ouvrir.
 * Également envoyée en push si l'appareil est abonné et le réglage actif.
 *
 * kind : ok | pay | due | seat | host
 */
class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Réglage utilisateur qui autorise le push, par type. */
    private const SETTING = [
        'ok' => 'notif_due',
        'pay' => 'notif_due',
        'due' => 'notif_due',
        'host' => 'notif_due',
        'seat' => 'notif_seats',
        'promo' => 'notif_promo',
    ];

    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public ?array $action = null,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        $settings = array_merge(User::DEFAULT_SETTINGS, $notifiable->settings ?? []);
        $allowed = (bool) ($settings[self::SETTING[$this->kind] ?? 'notif_due'] ?? true);

        if ($allowed && PushSubscription::where('user_id', $notifiable->getKey())->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /** L'onglet Activité est à jour tout de suite ; le push part en file d'attente. */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'action' => $this->action,
        ];
    }

    /** Charge utile lue par le service worker (public/push-sw.js). */
    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->action['to'] ?? '/activity',
            'tag' => $this->kind.':'.md5($this->title),
            'unread' => $notifiable->unreadNotifications()->count(),
        ];
    }
}
