<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Adresse d'abonnement Web Push : uniquement les services push des navigateurs.
 * Sinon le serveur enverrait des requêtes vers n'importe quelle URL (SSRF).
 */
class PushEndpoint implements ValidationRule
{
    private const HOSTS = [
        'fcm.googleapis.com',                   // Chrome, Edge (Android), Samsung…
        'updates.push.services.mozilla.com',    // Firefox
        'push.services.mozilla.com',
        '.push.apple.com',                      // Safari (web.push.apple.com)
        '.notify.windows.com',                  // Edge (Windows)
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $url = parse_url((string) $value);
        $host = strtolower($url['host'] ?? '');
        $ok = ($url['scheme'] ?? '') === 'https' && ! isset($url['port']) && ! isset($url['user'])
            && collect(self::HOSTS)->contains(fn ($h) => str_starts_with($h, '.') ? str_ends_with($host, $h) : $host === $h);

        if (! $ok) {
            $fail('Service de notifications non reconnu.');
        }
    }
}
