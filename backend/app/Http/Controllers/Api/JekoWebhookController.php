<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentType;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Webhook Jèko. Signature : en-tête Jeko-Signature = HMAC-SHA256(corps brut, secret), en hexadécimal.
 * Le contenu n'est jamais cru tel quel : on relit le statut via l'API avant de confirmer.
 * Jèko n'envoie rien quand un paiement échoue : la lecture régulière du statut prend le relais.
 */
class JekoWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentService $payments): JsonResponse
    {
        $secret = (string) config('services.jeko.webhook_secret');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        if ($secret === '' || ! hash_equals($expected, strtolower((string) $request->header('Jeko-Signature')))) {
            Log::warning('Jèko : webhook à la signature invalide', ['ip' => $request->ip()]);

            return response()->json(['error' => 'signature'], 401);
        }

        $event = (string) $request->header('Jeko-Event');
        if ($event !== 'TRANSACTION_COMPLETED' || $request->input('transactionType') !== 'payment') {
            return response()->json(['ok' => true]);
        }

        // transactionDetails.id = la demande de paiement ; transactionDetails.reference = notre référence + « _xxxxxx ».
        $requestId = $request->input('transactionDetails.id');
        $ours = $request->input('transactionDetails.reference');
        $subReference = is_string($ours) ? Str::beforeLast($ours, '_') : null;
        Log::info('Jèko : webhook reçu', ['request' => $requestId, 'reference' => $ours]);

        $handled = is_string($requestId) && $payments->handleWebhook($requestId);
        if (! $handled && $subReference) {
            // Repli : le paiement Sub.ci, relu à partir de sa dernière demande chez Jèko.
            $payment = Payment::where('reference', $subReference)->where('type', PaymentType::Subscription)->first();
            $handled = $payment?->provider_reference && $payments->handleWebhook($payment->provider_reference);
        }
        if (! $handled) {
            Log::warning('Jèko : webhook pour un paiement inconnu', ['request' => $requestId, 'reference' => $ours]);
        }

        return response()->json(['ok' => true]);
    }
}
