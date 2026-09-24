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
    ],

    'offers' => [
        // null = modération manuelle ; un nombre = mise en ligne auto après N secondes (dev).
        'auto_approve_after' => env('OFFERS_AUTO_APPROVE_AFTER') === null ? null : (int) env('OFFERS_AUTO_APPROVE_AFTER'),
    ],

    'otp' => [
        'ttl' => (int) env('OTP_TTL', 300),
        'max_attempts' => 5,
        // En local, le code est renvoyé dans la réponse (pas de passerelle SMS).
        'expose_code' => (bool) env('OTP_EXPOSE_CODE', env('APP_ENV') === 'local'),
    ],

];
