<?php

use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\GeniusPayWebhookController;
use App\Http\Controllers\Api\HostController;
use App\Http\Controllers\Api\JoinRequestController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OfferController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // ---------- Administration (comptes Admin, jamais les membres) ----------
    Route::prefix('admin')->group(function () {
        Route::post('auth/login', [Admin\AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('auth/2fa', [Admin\AuthController::class, 'twoFactor'])->middleware('throttle:10,1');

        // Configuration de la double authentification (jeton « admin-setup » ou session complète).
        Route::middleware(['auth:sanctum', 'admin.setup'])->group(function () {
            Route::post('auth/2fa/setup', [Admin\AuthController::class, 'setupTwoFactor'])->middleware('throttle:10,1');
            Route::post('auth/2fa/confirm', [Admin\AuthController::class, 'confirmTwoFactor'])->middleware('throttle:10,1');
            Route::get('auth/me', [Admin\AuthController::class, 'me']);
            Route::post('auth/logout', [Admin\AuthController::class, 'logout']);
        });

        Route::middleware(['auth:sanctum', 'admin'])->group(function () {
            Route::get('overview', Admin\OverviewController::class);
            Route::get('search', [Admin\MiscController::class, 'search']);

            Route::get('offers', [Admin\OfferController::class, 'index']);
            Route::get('offers/{offer}', [Admin\OfferController::class, 'show']);
            Route::get('offers/{offer}/proof', [Admin\OfferController::class, 'proof']);
            Route::post('offers/{offer}/approve', [Admin\OfferController::class, 'approve']);
            Route::post('offers/{offer}/reject', [Admin\OfferController::class, 'reject']);
            Route::post('offers/{offer}/toggle', [Admin\OfferController::class, 'toggle']);

            Route::get('payments', [Admin\PaymentController::class, 'index']);
            Route::get('payments/{payment}', [Admin\PaymentController::class, 'show']);
            Route::get('payouts', [Admin\PaymentController::class, 'payouts']);
            Route::post('payments/{payment}/paid', [Admin\PaymentController::class, 'markPaid']);
            Route::post('payments/{payment}/reconcile', [Admin\PaymentController::class, 'reconcile']);

            Route::get('users', [Admin\UserController::class, 'index']);
            Route::get('users/{user}', [Admin\UserController::class, 'show']);
            Route::post('users/{user}/suspend', [Admin\UserController::class, 'suspend']);
            Route::post('users/{user}/unsuspend', [Admin\UserController::class, 'unsuspend']);

            Route::get('requests', [Admin\MiscController::class, 'requests']);
            Route::post('requests/{joinRequest}/decline', [Admin\MiscController::class, 'declineRequest']);

            Route::get('disputes', [Admin\MiscController::class, 'disputes']);
            Route::post('disputes/{dispute}/resolve', [Admin\MiscController::class, 'resolveDispute']);

            Route::get('services', [Admin\MiscController::class, 'services']);
            Route::patch('services/{service:id}', [Admin\MiscController::class, 'updateService']);

            Route::get('audit', [Admin\MiscController::class, 'audit']);

            Route::get('push', [Admin\PushController::class, 'show']);
            Route::post('push/subscriptions', [Admin\PushController::class, 'store']);
            Route::delete('push/subscriptions', [Admin\PushController::class, 'destroy']);
            Route::patch('push/alerts', [Admin\PushController::class, 'alerts']);
            Route::post('push/test', [Admin\PushController::class, 'test'])->middleware('throttle:6,1');
        });
    });

    // Public
    Route::post('auth/otp', [AuthController::class, 'sendCode'])->middleware('throttle:otp');
    Route::post('auth/verify', [AuthController::class, 'verify'])->middleware('throttle:otp-verify');
    Route::get('services', [ServiceController::class, 'index']);
    Route::get('services/{service}', [ServiceController::class, 'show']);
    Route::get('push/key', [PushController::class, 'key']);
    Route::post('webhooks/geniuspay', GeniusPayWebhookController::class)->middleware('throttle:120,1');

    Route::middleware(['auth:sanctum', 'member'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('bootstrap', BootstrapController::class);
        // Offres d'un service (prénoms des hôtes et des membres) : réservé aux membres connectés.
        Route::get('services/{service}/offers', [OfferController::class, 'index']);
        // Liste d'attente : prévenu dès qu'une place se libère.
        Route::post('services/{service}/waitlist', [ServiceController::class, 'joinWaitlist'])->middleware('throttle:20,1');
        Route::delete('services/{service}/waitlist', [ServiceController::class, 'leaveWaitlist']);

        Route::post('push/subscriptions', [PushController::class, 'store']);
        Route::delete('push/subscriptions', [PushController::class, 'destroy']);

        Route::get('me', [MeController::class, 'show']);
        Route::patch('me', [MeController::class, 'update']);
        Route::post('me/referral', [MeController::class, 'referral'])->middleware('throttle:5,1');
        Route::post('me/payout/code', [MeController::class, 'payoutCode'])->middleware('throttle:3,1');
        Route::post('auth/logout-others', [MeController::class, 'logoutOthers']);

        Route::get('subscriptions', [SubscriptionController::class, 'index']);
        Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show']);
        Route::patch('subscriptions/{subscription}', [SubscriptionController::class, 'update']);
        Route::post('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel']);
        Route::post('subscriptions/{subscription}/dispute', [SubscriptionController::class, 'dispute'])->middleware('throttle:5,1');
        Route::post('subscriptions/{subscription}/invite/{status}', [SubscriptionController::class, 'invite'])->whereIn('status', ['joined', 'broken'])->middleware('throttle:10,1');
        Route::post('subscriptions/{subscription}/dispute/solve', [SubscriptionController::class, 'solveDispute']);

        Route::get('payments', [PaymentController::class, 'index']);
        Route::post('payments', [PaymentController::class, 'store'])->middleware('throttle:20,1');
        Route::get('payments/{payment:reference}', [PaymentController::class, 'show'])->middleware('throttle:60,1');
        Route::post('payments/{payment:reference}/resend', [PaymentController::class, 'resend'])->middleware('throttle:5,1');
        Route::post('payments/{payment:reference}/cancel', [PaymentController::class, 'cancel']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'read']);
        Route::post('notifications/{id}/archive', [NotificationController::class, 'archive']);
        Route::post('notifications/{id}/restore', [NotificationController::class, 'restore']);

        Route::post('join-requests/{joinRequest}/cancel', [JoinRequestController::class, 'cancel']);

        Route::get('host', [HostController::class, 'show']);
        Route::get('host/plans', [HostController::class, 'plans']);
        Route::post('host/requests/{joinRequest}/accept', [HostController::class, 'acceptRequest']);
        Route::post('host/requests/{joinRequest}/decline', [HostController::class, 'declineRequest']);
        Route::post('host/offers', [HostController::class, 'storeOffer']);
        Route::patch('host/offers/{offer}', [HostController::class, 'updateOffer']);
        Route::delete('host/offers/{offer}/members/{member}', [HostController::class, 'removeMember']);
        Route::post('host/offers/{offer}/{action}', [HostController::class, 'setStatus'])->whereIn('action', ['pause', 'resume', 'close']);
        Route::post('host/offers/{offer}/members/{member}/invite', [HostController::class, 'inviteMember'])->middleware('throttle:20,1');
        Route::post('host/withdrawals', [HostController::class, 'withdraw'])->middleware('throttle:5,1');
    });
});
