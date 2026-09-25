<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Configuration de la double authentification : session complète ou jeton « admin-setup ». */
class EnsureAdminSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof Admin && in_array($user->currentAccessToken()?->name, ['admin', 'admin-setup'], true), 403, 'Accès réservé à l’administration.');

        return $next($request);
    }
}
