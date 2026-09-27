<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook WhatsApp (Meta). On n'en a pas besoin pour envoyer les codes : il sert à valider
 * la configuration chez Meta et à repérer les codes non délivrés dans les logs.
 */
class WhatsAppWebhookController extends Controller
{
    /** Vérification de l'URL par Meta : hub.challenge renvoyé si le jeton correspond. */
    public function verify(Request $request): Response
    {
        $token = (string) config('services.whatsapp.webhook_verify_token');
        if ($token === '' || $request->query('hub_mode') !== 'subscribe' || ! hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response('Forbidden', 403);
        }

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): JsonResponse
    {
        // Signature Meta (X-Hub-Signature-256 = HMAC-SHA256 du corps avec la clé secrète de l'app), si configurée.
        $secret = (string) config('services.whatsapp.app_secret');
        if ($secret !== '') {
            $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
            if (! hash_equals($expected, (string) $request->header('X-Hub-Signature-256'))) {
                return response()->json(['error' => 'signature'], 401);
            }
        }

        // Codes non délivrés (numéro sans WhatsApp, etc.) : visibles dans les logs, sans numéro complet.
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                    if (($status['status'] ?? null) === 'failed') {
                        Log::warning('WhatsApp : message non délivré', [
                            'error' => $status['errors'][0]['title'] ?? null,
                            'code' => $status['errors'][0]['code'] ?? null,
                        ]);
                    }
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
