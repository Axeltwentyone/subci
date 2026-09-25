<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** En-têtes de sécurité sur toutes les réponses de l'API. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Resource-Policy' => 'same-site',
            // L'API ne sert que du JSON (et des images privées à l'admin) : rien à exécuter.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; frame-ancestors 'none'",
        ];
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($headers as $k => $v) {
            $response->headers->set($k, $v, false);
        }

        return $response;
    }
}
