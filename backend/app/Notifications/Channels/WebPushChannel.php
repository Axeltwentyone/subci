<?php

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Envoie la notification sur tous les appareils abonnés de l'utilisateur.
 * Les abonnements expirés (404 / 410 du service push) sont supprimés.
 */
class WebPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWebPush') || ! config('services.webpush.private_key')) {
            return;
        }

        $subscriptions = PushSubscription::where('user_id', $notifiable->getKey())->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $payload = json_encode($notification->toWebPush($notifiable), JSON_UNESCAPED_UNICODE);
        $push = new WebPush(['VAPID' => [
            'subject' => config('services.webpush.subject'),
            'publicKey' => config('services.webpush.public_key'),
            'privateKey' => config('services.webpush.private_key'),
        ]], ['TTL' => 24 * 3600, 'urgency' => 'normal']);

        foreach ($subscriptions as $sub) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $sub->endpoint,
                'publicKey' => $sub->public_key,
                'authToken' => $sub->auth_token,
                'contentEncoding' => $sub->content_encoding,
            ]), $payload);
        }

        foreach ($push->flush() as $report) {
            $hash = PushSubscription::hashEndpoint($report->getEndpoint());
            if ($report->isSuccess()) {
                PushSubscription::where('endpoint_hash', $hash)->update(['last_used_at' => now()]);
            } elseif ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint_hash', $hash)->delete();
            } else {
                Log::warning('Web Push échoué', ['reason' => $report->getReason()]);
            }
        }
    }
}
