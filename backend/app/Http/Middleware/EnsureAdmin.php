<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Routes d'administration : uniquement un compte Admin connecté via /admin/auth/login. */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof Admin && $user->currentAccessToken()?->name === 'admin', 403, 'Accès réservé à l’administration.');

        return $next($request);
    }
}
