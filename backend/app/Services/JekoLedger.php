<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\JekoTransaction;
use App\Models\MerchantBalance;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Relevé du compte marchand Jèko : solde réel et transactions (encaissements, envois) avec leurs frais réels.
 * Montants Jèko en centimes → FCFA. Encaissement : « amount » = crédité net, « fees » = commission.
 * Envoi : « amount » = envoyé, « fees » = en plus, débités du solde.
 */
class JekoLedger
{
    public static function configured(): bool
    {
        $c = config('services.jeko');

        return config('services.payments.driver') === 'jeko' && $c['key'] && $c['key_id'] && $c['store_id'];
    }

    /** @return array{configured: bool, balance?: ?int, imported?: int, linked?: int, error?: string} */
    public function sync(): array
    {
        if (! self::configured()) {
            return ['configured' => false];
        }
        try {
            $balance = $this->balance();
            $imported = $this->importTransactions();
        } catch (\Throwable $e) {
            Log::warning('Jèko : relevé impossible', ['error' => $e->getMessage()]);

            return ['configured' => true, 'error' => 'Jèko ne répond pas, réessaie dans un instant.'];
        }

        return ['configured' => true, 'balance' => $balance, 'imported' => $imported, 'linked' => $this->link()];
    }

    /** Solde réel du magasin, gardé dans l'historique des relevés. */
    public function balance(): ?int
    {
        $response = $this->http()->get('/partner_api/stores/'.rawurlencode(config('services.jeko.store_id')).'/balance');
        $cents = $response->json('amount');
        if (! $response->successful() || ! is_numeric($cents) || strtoupper((string) $response->json('currency', 'XOF')) !== 'XOF') {
            throw new \RuntimeException('Solde Jèko illisible ('.$response->status().')');
        }
        $amount = intdiv((int) $cents, 100);
        $last = MerchantBalance::where('source', 'jeko')->latest('recorded_at')->first();
        // Nouveau relevé si le solde a changé, sinon toutes les 6 h (date du dernier relevé confirmé).
        if (! $last || $last->amount !== $amount || $last->recorded_at->lt(now()->subHours(6))) {
            MerchantBalance::create(['amount' => $amount, 'source' => 'jeko', 'recorded_at' => now()]);
        }

        return $amount;
    }

    private function importTransactions(): int
    {
        $count = 0;
        for ($page = 1; $page <= 50; $page++) {
            $response = $this->http()->get('/partner_api/transactions', ['storeId' => config('services.jeko.store_id'), 'limit' => 100, 'page' => $page]);
            if (! $response->successful()) {
                throw new \RuntimeException('Relevé Jèko illisible ('.$response->status().')');
            }
            $rows = (array) $response->json('data', []);
            foreach ($rows as $t) {
                if (! isset($t['id'], $t['type'], $t['amount']['amount'], $t['createdAt'])) {
                    continue;
                }
                $model = JekoTransaction::firstOrNew(['jeko_id' => (string) $t['id']]);
                $model->fill([
                    'type' => (string) $t['type'],
                    'status' => (string) ($t['status'] ?? 'pending'),
                    'method' => $t['paymentMethod'] ?? null,
                    'amount' => intdiv((int) $t['amount']['amount'], 100),
                    'fee' => intdiv((int) ($t['fees']['amount'] ?? 0), 100),
                    'counterpart' => isset($t['counterpartIdentifier']) ? mb_substr((string) $t['counterpartIdentifier'], 0, 64) : null,
                    'jeko_reference' => isset($t['reference']) ? mb_substr((string) $t['reference'], 0, 64) : null,
                    'executed_at' => CarbonImmutable::parse($t['executedAt'] ?? $t['createdAt']),
                ]);
                if ($model->isDirty()) {
                    $model->save();
                    $count++;
                }
            }
            $total = (int) $response->json('total', 0);
            if (! $rows || $page * max(1, (int) $response->json('perPage', 100)) >= $total) {
                break;
            }
        }

        return $count;
    }

    /** Rattache les transactions Jèko aux paiements Sub.ci, et reporte les frais réels. */
    public function link(): int
    {
        $linked = 0;
        JekoTransaction::where('status', 'success')->whereNull('payment_id')->whereNull('treasury_withdrawal_id')->whereNull('ignored_reason')
            ->orderBy('executed_at')->get()
            ->each(function (JekoTransaction $t) use (&$linked) {
                $payment = $t->type === 'payment' ? $this->matchCollection($t) : $this->matchPayout($t);
                if ($payment) {
                    $this->attach($t, $payment);
                    $linked++;
                }
            });

        return $linked;
    }

    public function attach(JekoTransaction $t, Payment $payment): void
    {
        $t->update(['payment_id' => $payment->id]);
        $payment->update(['provider_fee' => $t->fee, 'provider_transaction_id' => $t->jeko_id]);
    }

    private function matchCollection(JekoTransaction $t): ?Payment
    {
        $byId = Payment::where('provider_transaction_id', $t->jeko_id)->first();
        if ($byId) {
            return $byId;
        }

        // Même montant brut, même numéro, confirmé autour de l'exécution chez Jèko, pas encore rattaché.
        return $this->closest(Payment::where('type', PaymentType::Subscription)->where('status', PaymentStatus::Succeeded)
            ->where('amount', $t->amount + $t->fee)
            ->whereBetween('confirmed_at', [$t->executed_at->copy()->subHours(6), $t->executed_at->copy()->addDay()]), $t);
    }

    private function matchPayout(JekoTransaction $t): ?Payment
    {
        return $this->closest(Payment::whereIn('type', [PaymentType::Withdrawal, PaymentType::Refund])
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Succeeded])
            ->where(fn ($q) => $q->whereNull('refund_choice')->orWhere('refund_choice', 'cash'))
            ->where('amount', $t->amount)
            ->where('created_at', '<=', $t->executed_at->copy()->addHour()), $t);
    }

    private function closest($query, JekoTransaction $t): ?Payment
    {
        $phone = self::localPhone($t->counterpart);

        // Pas déjà rattaché à une autre ligne du relevé.
        return $query->whereNotIn('id', JekoTransaction::whereNotNull('payment_id')->select('payment_id'))
            ->when($phone, fn ($q) => $q->where('phone', $phone))
            ->get()
            ->sortBy(fn (Payment $p) => abs(($p->confirmed_at ?? $p->created_at)->diffInSeconds($t->executed_at)))
            ->first();
    }

    /** Numéros des bénéficiaires déjà enregistrés chez Jèko (null si Jèko n'est pas joignable). */
    public function contactNumbers(): ?array
    {
        if (! self::configured()) {
            return null;
        }

        return \Illuminate\Support\Facades\Cache::remember('jeko:contacts', 300, function () {
            try {
                $response = $this->http()->get('/partner_api/contacts');
            } catch (\Throwable) {
                return null;
            }
            $rows = $response->json();
            if (! $response->successful() || ! is_array($rows)) {
                return null;
            }
            $rows = array_is_list($rows) ? $rows : (array) ($rows['data'] ?? []);

            return array_values(array_filter(array_map(fn ($c) => is_array($c) ? ($c['identifier']['number'] ?? null) : null, $rows)));
        });
    }

    /** « +2250143998478 » → « 0143998478 ». */
    public static function localPhone(?string $counterpart): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $counterpart);

        return preg_match('/^(?:225)?(0[157]\d{8})$/', $digits, $m) ? $m[1] : null;
    }

    private function http(): PendingRequest
    {
        $c = config('services.jeko');

        return Http::baseUrl($c['base_url'])
            ->withHeaders(['X-API-KEY' => $c['key'], 'X-API-KEY-ID' => $c['key_id']])
            ->acceptJson()
            ->timeout(20);
    }
}
