<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\HostController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::post('auth/otp', [AuthController::class, 'sendCode'])->middleware('throttle:otp');
    Route::post('auth/verify', [AuthController::class, 'verify'])->middleware('throttle:otp-verify');
    Route::get('services', [ServiceController::class, 'index']);
    Route::get('services/{service}', [ServiceController::class, 'show']);
    Route::get('push/key', [PushController::class, 'key']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('bootstrap', BootstrapController::class);

        Route::post('push/subscriptions', [PushController::class, 'store']);
        Route::delete('push/subscriptions', [PushController::class, 'destroy']);

        Route::get('me', [MeController::class, 'show']);
        Route::patch('me', [MeController::class, 'update']);

        Route::get('subscriptions', [SubscriptionController::class, 'index']);
        Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show']);
        Route::patch('subscriptions/{subscription}', [SubscriptionController::class, 'update']);
        Route::post('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel']);

        Route::get('payments', [PaymentController::class, 'index']);
        Route::post('payments', [PaymentController::class, 'store'])->middleware('throttle:20,1');
        Route::get('payments/{payment:reference}', [PaymentController::class, 'show']);
        Route::post('payments/{payment:reference}/resend', [PaymentController::class, 'resend'])->middleware('throttle:5,1');
        Route::post('payments/{payment:reference}/cancel', [PaymentController::class, 'cancel']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'read']);
        Route::post('notifications/{id}/archive', [NotificationController::class, 'archive']);
        Route::post('notifications/{id}/restore', [NotificationController::class, 'restore']);

        Route::get('host', [HostController::class, 'show']);
        Route::post('host/offers', [HostController::class, 'storeOffer']);
        Route::patch('host/offers/{offer}', [HostController::class, 'updateOffer']);
        Route::delete('host/offers/{offer}/members/{member}', [HostController::class, 'removeMember']);
        Route::post('host/offers/{offer}/{action}', [HostController::class, 'setStatus'])->whereIn('action', ['pause', 'resume', 'close']);
        Route::post('host/offers/{offer}/invite', [HostController::class, 'invite']);
        Route::post('host/withdrawals', [HostController::class, 'withdraw'])->middleware('throttle:5,1');
    });
});
