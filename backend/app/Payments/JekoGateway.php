<?php

namespace App\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Jèko (https://developer.jeko.africa) : demande de paiement « redirect », le client valide
 * dans son appli mobile money puis revient sur /retour/{référence Sub.ci}. Confirmation par
 * webhook signé (TRANSACTION_COMPLETED) et, en secours, par lecture du statut.
 * Pas d'environnement de test chez Jèko : tester sur une boutique dédiée, avec de petits montants.
 */
class JekoGateway implements PaymentGateway
{
    /** Moyen choisi dans l'app → `paymentMethod` Jèko (pas de carte dans l'API). */
    private const METHODS = [
        'om' => 'orange',
        'mtn' => 'mtn',
        'wave' => 'wave',
        'moov' => 'moov',
    ];

    public function __construct(private array $config) {}

    public function request(Payment $payment): array
    {
        $method = self::METHODS[$payment->method->value] ?? null;
        if ($method === null) {
            throw new HttpException(422, 'La carte bancaire n’est pas encore disponible. Choisis Wave, Orange Money, MTN ou Moov.');
        }
        $return = $payment->return_url ?? rtrim((string) config('app.frontend_url'), '/')."/retour/{$payment->reference}";

        // Référence unique par tentative (Jèko refuse un doublon) ; la partie avant « _ » est la nôtre.
        $response = $this->http()->post('/partner_api/payment_requests', [
            'storeId' => $this->config['store_id'],
            'amountCents' => $payment->amount * 100,
            'currency' => 'XOF',
            'reference' => $payment->reference.'_'.Str::lower(Str::random(6)),
            'paymentDetails' => [
                'type' => 'redirect',
                'data' => [
                    'paymentMethod' => $method,
                    'successUrl' => $return,
                    'errorUrl' => $return,
                ],
            ],
        ]);

        if (! $response->successful() || ! $response->json('id')) {
            Log::error('Jèko : création du paiement refusée', [
                'status' => $response->status(),
                // Jèko renvoie {id: "business_not_enabled_for_api_access", message: …} ; pas de clé dans la réponse.
                'error' => $response->json('id') ?? $response->json('code') ?? $response->json('error'),
                'message' => $response->json('message'),
                'body' => Str::limit($response->body(), 500),
            ]);
            throw new HttpException(502, 'Le paiement n’a pas pu démarrer. Réessaie dans un instant.');
        }

        return ['reference' => (string) $response->json('id'), 'url' => $response->json('redirectUrl')];
    }

    public function status(Payment $payment, ?string $reference = null): PaymentStatus
    {
        $reference ??= $payment->provider_reference;
        if (! $reference) {
            return PaymentStatus::Pending;
        }
        $response = $this->http()->get('/partner_api/payment_requests/'.rawurlencode($reference));
        if (! $response->successful()) {
            // Réseau ou Jèko indisponible : on réessaiera au prochain tour.
            return PaymentStatus::Pending;
        }

        $status = self::map((string) $response->json('status'));
        $amount = $response->json('transaction.amount.amount');
        $currency = $response->json('transaction.amount.currency');
        $context = ['payment' => $payment->reference, 'jeko' => $reference, 'status' => $response->json('status'),
            'amount' => $amount, 'fees' => $response->json('transaction.fees.amount'), 'reason' => $response->json('errorReason')];
        if ($status !== PaymentStatus::Pending) {
            Log::info('Jèko : statut lu', $context);
        }

        // Réussi seulement si Jèko donne le montant (en centimes) et qu'il couvre ce qui était demandé.
        if ($status === PaymentStatus::Succeeded
            && ($amount === null || (int) $amount < $payment->amount * 100 || ($currency !== null && strtoupper((string) $currency) !== 'XOF'))) {
            Log::warning('Jèko : montant incohérent', $context);

            return PaymentStatus::Failed;
        }

        return $status;
    }

    public static function map(string $status): PaymentStatus
    {
        return match ($status) {
            'success' => PaymentStatus::Succeeded,
            'error' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->config['base_url'])
            ->withHeaders(['X-API-KEY' => $this->config['key'], 'X-API-KEY-ID' => $this->config['key_id']])
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->retry(2, 300, throw: false);
    }
}
