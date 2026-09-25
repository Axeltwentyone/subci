<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Routes de l'app : uniquement un membre, et pas un compte suspendu. */
class EnsureMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'Accès réservé aux membres.');
        if ($user->suspended_at) {
            $user->currentAccessToken()?->delete();
            abort(403, 'Ton compte est suspendu. Contacte le support Sub.ci.');
        }

        return $next($request);
    }
}
