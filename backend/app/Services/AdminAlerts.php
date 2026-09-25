<?php

namespace App\Services;

use App\Jobs\SendAdminAlert;

/**
 * Notifications push pour l'équipe (app d'administration installée).
 * Chaque admin choisit ses alertes ; envoyées après commit, via la file d'attente.
 */
class AdminAlerts
{
    /** Type => [libellé, activé par défaut]. */
    public const KINDS = [
        'payments' => ['Paiement reçu', true],
        'offers' => ['Offre à valider', true],
        'payouts' => ['Versement à faire', true],
        'disputes' => ['Souci signalé', true],
        'signups' => ['Nouvelle inscription', false],
    ];

    public static function send(string $kind, string $title, string $body, string $url, ?string $tag = null): void
    {
        if (! isset(self::KINDS[$kind]) || ! WebPushSender::configured()) {
            return;
        }

        SendAdminAlert::dispatch($kind, [
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'tag' => $tag,
        ])->afterCommit();
    }

    public static function fcfa(int $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }
}
