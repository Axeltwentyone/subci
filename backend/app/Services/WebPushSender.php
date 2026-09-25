<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Envoi Web Push (VAPID) vers une liste d'appareils.
 * Les abonnements expirés (404 / 410 du service push) sont supprimés.
 */
class WebPushSender
{
    public static function configured(): bool
    {
        return (bool) config('services.webpush.private_key');
    }

    /** @param  Collection<int, PushSubscription>  $subscriptions */
    public function send(Collection $subscriptions, array $payload, string $urgency = 'normal'): void
    {
        if ($subscriptions->isEmpty() || ! self::configured()) {
            return;
        }

        $push = new WebPush(['VAPID' => [
            'subject' => config('services.webpush.subject'),
            'publicKey' => config('services.webpush.public_key'),
            'privateKey' => config('services.webpush.private_key'),
        ]], ['TTL' => 24 * 3600, 'urgency' => $urgency]);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        foreach ($subscriptions as $sub) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $sub->endpoint,
                'publicKey' => $sub->public_key,
                'authToken' => $sub->auth_token,
                'contentEncoding' => $sub->content_encoding,
            ]), $json);
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
