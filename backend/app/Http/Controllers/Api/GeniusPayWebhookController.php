<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook GeniusPay. Signature : HMAC-SHA256(timestamp + "." + corps brut, secret).
 * Le contenu n'est jamais cru tel quel : on relit le statut via l'API avant de confirmer.
 */
class GeniusPayWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentService $payments): JsonResponse
    {
        $secret = (string) config('services.geniuspay.webhook_secret');
        $timestamp = (string) $request->header('X-Webhook-Timestamp');
        $signature = preg_replace('/^sha256=/', '', (string) $request->header('X-Webhook-Signature'));
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if ($secret === '' || ! hash_equals($expected, $signature)) {
            Log::warning('GeniusPay : webhook à la signature invalide', ['ip' => $request->ip()]);

            return response()->json(['error' => 'signature'], 401);
        }
        // Rejeu : horodatage en secondes ou millisecondes, 5 min de tolérance.
        $ts = (int) $timestamp > 1e12 ? intdiv((int) $timestamp, 1000) : (int) $timestamp;
        if (abs(time() - $ts) > 300) {
            return response()->json(['error' => 'expired'], 401);
        }

        $event = (string) $request->input('event', $request->header('X-Webhook-Event'));
        $reference = $request->input('data.reference') ?? $request->input('reference');
        // Référence Sub.ci renvoyée dans les métadonnées : retrouve le paiement même si GeniusPay
        // envoie une autre référence que celle reçue à la création (nouvelle tentative, autre moyen).
        $subReference = $request->input('data.metadata.sub_reference');

        Log::info('GeniusPay : webhook reçu', ['event' => $event, 'reference' => $reference, 'sub_reference' => $subReference]);
        if (str_starts_with($event, 'payment.') && $reference
            && ! $payments->handleWebhook((string) $reference, is_string($subReference) ? $subReference : null)) {
            Log::warning('GeniusPay : webhook pour un paiement inconnu', ['reference' => $reference, 'event' => $event]);
        }

        return response()->json(['ok' => true]);
    }
}
