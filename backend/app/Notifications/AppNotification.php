<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notification de l'onglet « Activité ». Chaque notif porte son action
 * (ex. « Renouveler ») pour que l'utilisateur n'ait pas à l'ouvrir.
 *
 * kind : ok | pay | due | seat | host
 */
class AppNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public ?array $action = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
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
}
