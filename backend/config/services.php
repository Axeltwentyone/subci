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
        // Frais de service Sub.ci ajoutés à chaque paiement de membre (couvrent les frais fixes de la passerelle).
        // Plus élevés sur un paiement d'1 mois (les frais fixes de la passerelle reviennent chaque mois).
        'service_fee' => (int) env('SERVICE_FEE', 300),
        // Paiement de 3 mois ou plus.
        'service_fee_long' => (int) env('SERVICE_FEE_LONG', 200),
        // Séquestre : chaque mois payé est versé à l'hôte au début du mois + ce délai (litige possible avant).
        'hold_hours' => (int) env('PAYMENTS_HOLD_HOURS', 48),
        // Hôte fiable (3 mois d'activité, 3 mois déjà versés, aucun souci fondé depuis 90 jours) : délai réduit.
        'trusted_hold_hours' => (int) env('PAYMENTS_TRUSTED_HOLD_HOURS', 24),
        // Retraits bloqués après un changement de numéro de retrait.
        // Retraits des hôtes : minimum, et frais d'envoi à la charge de l'hôte (fixe + %).
        'withdrawal_min' => (int) env('WITHDRAWAL_MIN', 2000),
        'withdrawal_fee_fixed' => (int) env('WITHDRAWAL_FEE_FIXED', 0),
        'withdrawal_fee_percent' => (float) env('WITHDRAWAL_FEE_PERCENT', 1),
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

    'referral' => [
        // Crédit Sub.ci du parrain au 2e paiement abouti de son filleul (déduit de ses paiements, non retirable).
        'reward' => (int) env('REFERRAL_REWARD', 300),
        'monthly_cap' => (int) env('REFERRAL_MONTHLY_CAP', 10),
        // Frais de service offerts au filleul jusqu'à sa première acceptation (coûte plus qu'il ne rapporte : coupé).
        'waive_fee' => (bool) env('REFERRAL_WAIVE_FEE', false),
        // Montant minimum réellement payé après crédit (la passerelle refuse les paiements à 0).
        'min_payable' => (int) env('REFERRAL_MIN_PAYABLE', 200),
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
        // Bêta : un code commun pour tous les numéros, jusqu'à une date obligatoire (coupé automatiquement ensuite).
        // Quiconque connaît le code peut se connecter sur n'importe quel numéro : bêta fermée uniquement.
        'beta_code' => preg_match('/^\d{6}$/', (string) env('OTP_BETA_CODE')) ? (string) env('OTP_BETA_CODE') : null,
        'beta_until' => env('OTP_BETA_UNTIL'),
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
