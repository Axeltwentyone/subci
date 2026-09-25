<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureAdminSetup;
use App\Http\Middleware\EnsureMember;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API en jeton (Sanctum) : pas de redirection vers une page de login, un 401 JSON.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
        $middleware->append(SecurityHeaders::class);
        // Limite globale : 240 requêtes / min par compte (ou par IP sans compte).
        $middleware->throttleApi('api');
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'admin.setup' => EnsureAdminSetup::class,
            'member' => EnsureMember::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
