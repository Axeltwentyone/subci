<?php

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use App\Services\WebPushSender;
use Illuminate\Notifications\Notification;

/** Envoie la notification sur tous les appareils abonnés de l'utilisateur. */
class WebPushChannel
{
    public function __construct(private WebPushSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWebPush') || ! WebPushSender::configured()) {
            return;
        }

        $this->sender->send(
            PushSubscription::where('user_id', $notifiable->getKey())->get(),
            $notification->toWebPush($notifiable),
        );
    }
}
