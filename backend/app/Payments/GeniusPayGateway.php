<?php

namespace App\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * GeniusPay (https://pay.genius.ci/doc) : le client valide sur la page de
 * paiement GeniusPay puis revient sur /pay/{référence Sub.ci}. Confirmation par
 * webhook signé et, en secours, par lecture du statut.
 */
class GeniusPayGateway implements PaymentGateway
{
    /** Moyen choisi dans l'app → `payment_method` GeniusPay (null = page de choix GeniusPay). */
    private const METHODS = [
        'om' => 'orange_money',
        'mtn' => 'mtn_money',
        'wave' => 'wave',
        'card' => 'card',
        'moov' => null,
    ];

    public function __construct(private array $config) {}

    public function request(Payment $payment): array
    {
        $payment->loadMissing('user', 'service');
        $return = $payment->return_url ?? rtrim((string) config('app.frontend_url'), '/')."/pay/{$payment->reference}";

        $body = array_filter([
            'amount' => $payment->amount,
            'currency' => 'XOF',
            'description' => "Sub.ci · {$payment->label}",
            'payment_method' => self::METHODS[$payment->method->value] ?? null,
            'customer' => array_filter([
                'name' => $payment->user->name ?? 'Membre Sub.ci',
                'phone' => $payment->phone ? '+225'.$payment->phone : null,
                'country' => 'CI',
            ]),
            'metadata' => ['sub_reference' => $payment->reference, 'user_id' => $payment->user_id],
            'success_url' => $return,
            'error_url' => $return,
        ], fn ($v) => $v !== null);

        $response = $this->http()->post('/payments', $body);
        if (! $response->successful() || ! $response->json('success')) {
            Log::error('GeniusPay : création du paiement refusée', ['status' => $response->status(), 'body' => $response->json() ?? $response->body()]);
            throw new HttpException(502, 'Le paiement n’a pas pu démarrer. Réessaie dans un instant.');
        }

        return [
            'reference' => (string) $response->json('data.reference'),
            'url' => $response->json('data.payment_url') ?? $response->json('data.checkout_url'),
        ];
    }

    public function status(Payment $payment, ?string $reference = null): PaymentStatus
    {
        $reference ??= $payment->provider_reference;
        if (! $reference) {
            return PaymentStatus::Pending;
        }
        $response = $this->http()->get('/payments/'.urlencode($reference));
        if (! $response->successful()) {
            // Réseau ou GeniusPay indisponible : on réessaiera au prochain tour.
            return PaymentStatus::Pending;
        }

        // Montant ou devise différents de la demande : on ne confirme pas.
        $currency = $response->json('data.currency');
        if ((int) $response->json('data.amount') !== $payment->amount || ($currency !== null && strtoupper((string) $currency) !== 'XOF')) {
            Log::warning('GeniusPay : montant incohérent', ['payment' => $payment->reference, 'amount' => $response->json('data.amount')]);

            return PaymentStatus::Failed;
        }

        return self::map((string) $response->json('data.status'));
    }

    public static function map(string $status): PaymentStatus
    {
        return match ($status) {
            'completed' => PaymentStatus::Succeeded,
            'failed', 'cancelled', 'refunded' => PaymentStatus::Failed,
            'expired' => PaymentStatus::Expired,
            default => PaymentStatus::Pending,
        };
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->config['base_url'])
            ->withHeaders(['X-API-Key' => $this->config['key'], 'X-API-Secret' => $this->config['secret']])
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->retry(2, 300, throw: false);
    }
}
