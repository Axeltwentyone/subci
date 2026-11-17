<?php

namespace App\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Jèko (https://developer.jeko.africa) : demande de paiement « redirect », le client valide
 * dans son appli mobile money puis revient sur /retour/{référence Subci}. Confirmation par
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
        'djamo' => 'djamo',
    ];

    /** Moyens payés sans la page Jèko quand on connaît le numéro du client. */
    private const DIRECT = ['wave', 'mtn', 'moov', 'djamo'];

    public function __construct(private array $config) {}

    /** Référence unique par tentative (Jèko refuse un doublon) ; la partie avant « _ » est la nôtre. */
    private function create(Payment $payment, array $data): Response
    {
        return $this->http()->post('/partner_api/payment_requests', [
            'storeId' => $this->config['store_id'],
            'amountCents' => $payment->amount * 100,
            'currency' => 'XOF',
            'reference' => $payment->reference.'_'.Str::lower(Str::random(6)),
            'paymentDetails' => ['type' => 'redirect', 'data' => $data],
        ]);
    }

    public function request(Payment $payment): array
    {
        $method = self::METHODS[$payment->method->value] ?? null;
        if ($method === null) {
            throw new HttpException(422, 'La carte bancaire n’est pas encore disponible. Choisis Wave, Orange Money, MTN ou Moov.');
        }
        $return = $payment->return_url ?? rtrim((string) config('app.frontend_url'), '/')."/retour/{$payment->reference}";
        // Jèko refuse les adresses locales (http://localhost…) : on repasse alors par l'API, qui redirige.
        if (! self::isPublicUrl($return)) {
            $return = self::apiRoot()."/api/v1/payments/return/{$payment->reference}";
        }

        $data = ['paymentMethod' => $method, 'successUrl' => $return, 'errorUrl' => $return];
        // Mode direct : Jèko ne redemande pas le numéro. Wave et Djamo renvoient directement leur lien de paiement,
        // MTN et Moov envoient la demande en USSD sur le téléphone. Orange garde la page Jèko.
        $direct = in_array($method, self::DIRECT, true) && preg_match('/^0[157]\d{8}$/', (string) $payment->phone);
        $response = $direct
            ? $this->create($payment, $data + ['forceProviderDirect' => true, 'payerPhone' => '+225'.$payment->phone])
            : null;
        if ($response === null || ! $response->successful() || ! $response->json('id')) {
            if ($response !== null) {
                Log::warning('Jèko : mode direct refusé, repli sur la page Jèko', [
                    'status' => $response->status(),
                    'error' => $response->json('id') ?? $response->json('code'),
                ]);
            }
            $response = $this->create($payment, $data);
        }

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

        // Commission réellement prélevée par Jèko (centimes → FCFA), pour la trésorerie.
        $transactionId = $response->json('transaction.id');
        $fees = $response->json('transaction.fees.amount');
        if ($status === PaymentStatus::Succeeded && $payment->exists && $payment->provider_transaction_id === null && is_string($transactionId) && is_numeric($fees)
            && $reference === $payment->provider_reference) {
            $payment->forceFill(['provider_fee' => intdiv((int) $fees, 100), 'provider_transaction_id' => mb_substr($transactionId, 0, 64)])->saveQuietly();
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

    /** https et un vrai nom de domaine (pas localhost, ni .test / .localhost). */
    public static function isPublicUrl(?string $url): bool
    {
        $parts = parse_url((string) $url);
        $host = strtolower($parts['host'] ?? '');

        return ($parts['scheme'] ?? '') === 'https'
            && str_contains($host, '.')
            && ! str_ends_with($host, '.localhost')
            && ! str_ends_with($host, '.test')
            && ! filter_var($host, FILTER_VALIDATE_IP);
    }

    private static function apiRoot(): string
    {
        $url = rtrim((string) config('app.url'), '/');

        return self::isPublicUrl($url.'/') ? $url : 'https://'.request()->getHost();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->config['base_url'])
            ->withHeaders(['X-API-KEY' => $this->config['key'], 'X-API-KEY-ID' => $this->config['key_id']])
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            // On ne relance que sur coupure réseau ou panne Jèko : un refus (4xx) ne changera pas.
            ->retry(2, 300, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }
}
