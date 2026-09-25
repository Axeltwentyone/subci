<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Payments\FakeGateway;
use App\Payments\GeniusPayGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, fn () => match (config('services.payments.driver')) {
            'geniuspay' => new GeniusPayGateway(config('services.geniuspay')),
            default => new FakeGateway(config('services.payments.fake_delay')),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Envoi d'OTP : 5 / min par numéro et par IP ; vérification : 10 / min.
        // Par session (jeton), sinon par IP : en Côte d'Ivoire beaucoup d'abonnés mobiles partagent une IP.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by($r->bearerToken() ? 'tok:'.hash('sha256', $r->bearerToken()) : 'ip:'.$r->ip()));
        RateLimiter::for('otp', fn (Request $r) => [
            Limit::perMinute(5)->by('otp:'.$r->input('phone')),
            Limit::perMinute(10)->by('otp-ip:'.$r->ip()),
        ]);
        RateLimiter::for('otp-verify', fn (Request $r) => Limit::perMinute(10)->by('otpv:'.$r->input('phone').'|'.$r->ip()));
    }
}
