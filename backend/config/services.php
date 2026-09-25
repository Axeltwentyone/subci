<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Paiements mobile money. Le driver « fake » confirme automatiquement une
     * demande au bout de `fake_delay` secondes (dev / démo). Un vrai agrégateur
     * (CinetPay, PayDunya…) s'implémente via App\Contracts\PaymentGateway.
     */
    'payments' => [
        'driver' => env('PAYMENTS_DRIVER', 'fake'),
        'fake_delay' => (int) env('PAYMENTS_FAKE_DELAY', 5),
        'request_ttl' => (int) env('PAYMENTS_REQUEST_TTL', 102),
        // GeniusPay n'expose pas d'API de remboursement ni de versement à un tiers :
        // remboursements et retraits des hôtes sont alors traités à la main (payouts:*).
        // Séquestre : chaque mois payé est versé à l'hôte au début du mois + ce délai (litige possible avant).
        'hold_hours' => (int) env('PAYMENTS_HOLD_HOURS', 48),
        // Hôte fiable (3 mois d'activité, 3 mois déjà versés, aucun souci fondé depuis 90 jours) : délai réduit.
        'trusted_hold_hours' => (int) env('PAYMENTS_TRUSTED_HOLD_HOURS', 24),
        // Retraits bloqués après un changement de numéro de retrait.
        'payout_change_lock_hours' => (int) env('PAYOUT_CHANGE_LOCK_HOURS', 24),
        // Délai minimal entre deux lectures du statut chez la passerelle pour un même paiement.
        'poll_interval' => (int) env('PAYMENTS_POLL_INTERVAL', 3),
        'manual_payouts' => (bool) env('PAYMENTS_MANUAL_PAYOUTS', env('PAYMENTS_DRIVER') === 'geniuspay'),
    ],

    'geniuspay' => [
        'base_url' => env('GENIUSPAY_BASE_URL', 'https://pay.genius.ci/api/v1/merchant'),
        'key' => env('GENIUSPAY_API_KEY'),
        'secret' => env('GENIUSPAY_API_SECRET'),
        'webhook_secret' => env('GENIUSPAY_WEBHOOK_SECRET'),
    ],

    'offers' => [
        // null = modération manuelle ; un nombre = mise en ligne auto après N secondes (dev).
        // Rappel si un lien d'invitation famille n'est pas utilisé (Spotify : expire vers 7 jours).
        'invite_reminder_days' => (int) env('INVITE_REMINDER_DAYS', 5),
        'auto_approve_after' => env('OFFERS_AUTO_APPROVE_AFTER') === null ? null : (int) env('OFFERS_AUTO_APPROVE_AFTER'),
    ],

    'webpush' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:contact@sub.ci'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    'admin' => [
        // Double authentification obligatoire pour l'administration.
        'require_2fa' => (bool) env('ADMIN_REQUIRE_2FA', true),
    ],

    'otp' => [
        'ttl' => (int) env('OTP_TTL', 300),
        'max_attempts' => 5,
        // Par numéro : codes envoyés par heure / par jour, échecs tolérés par 24 h.
        'per_hour' => (int) env('OTP_PER_HOUR', 6),
        'per_day' => (int) env('OTP_PER_DAY', 12),
        'max_failures' => (int) env('OTP_MAX_FAILURES', 15),
        // Numéros de test sans SMS : « 0700000000:482913,0102030405:111222 » (secret, à retirer avant l'ouverture).
        'test_codes' => collect(explode(',', (string) env('OTP_TEST_CODES', '')))
            ->map(fn ($pair) => array_map('trim', explode(':', $pair, 2)))
            ->filter(fn ($p) => count($p) === 2 && preg_match('/^\d{10}$/', $p[0]) && preg_match('/^\d{6}$/', $p[1]))
            ->mapWithKeys(fn ($p) => [$p[0] => $p[1]])->all(),
        // En local uniquement, le code est renvoyé dans la réponse (pas de passerelle SMS).
        // Jamais en production, même si OTP_EXPOSE_CODE est laissé à true par erreur.
        'expose_code' => in_array(env('APP_ENV'), ['local', 'testing'], true) && (bool) env('OTP_EXPOSE_CODE', true),
    ],

];
