<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Payment;
use App\Notifications\AppNotification;
use App\Support\PayoutCheck;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoi automatique des versements (retraits des hôtes, remboursements en argent) par l'API Jèko.
 *
 * Sécurité : un versement n'est tenté qu'UNE fois (payout_attempted_at posé avant l'appel, sous verrou),
 * et la référence Jèko du transfert est celle du paiement Sub.ci (unique chez Jèko : un doublon est refusé).
 * Au moindre doute (réseau, refus), on n'envoie plus rien : le versement reste à faire à la main, l'équipe est alertée.
 */
class JekoPayouts
{
    /** Ce versement part-il tout seul ? (Jèko configuré, opérateur activé, numéro valable.) */
    public static function handles(Payment $payment): bool
    {
        return JekoLedger::configured()
            && in_array($payment->type, [PaymentType::Withdrawal, PaymentType::Refund], true)
            && in_array($payment->method->value, (array) config('services.jeko.auto_payout_methods'), true)
            && PayoutCheck::check($payment->method, $payment->phone)['ok'];
    }

    /** Envoie les versements en attente et suit ceux en cours chez Jèko (appelé chaque minute). */
    public function run(): int
    {
        if (! JekoLedger::configured()) {
            return 0;
        }
        $done = 0;
        // Suivi des transferts déjà créés.
        Payment::whereIn('type', [PaymentType::Withdrawal, PaymentType::Refund])->where('status', PaymentStatus::Pending)
            ->whereNotNull('payout_attempted_at')->whereNotNull('provider_transaction_id')->whereNull('payout_error')
            ->limit(50)->get()->each(function (Payment $p) use (&$done) {
                $done += (int) $this->follow($p);
            });
        // Nouveaux versements (hors remboursements en attente du choix du membre).
        Payment::toPayOut()->whereNull('payout_attempted_at')->oldest()->limit(20)->get()
            ->filter(fn (Payment $p) => self::handles($p))
            ->each(function (Payment $p) use (&$done) {
                $done += (int) $this->send($p);
            });

        return $done;
    }

    public function send(Payment $payment): bool
    {
        // Verrou : une seule tentative, même si deux tâches tournent en même temps.
        $claimed = DB::transaction(function () use ($payment) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if ($locked->status !== PaymentStatus::Pending || $locked->payout_attempted_at || $locked->refund_choice === 'pending') {
                return false;
            }
            $locked->update(['payout_attempted_at' => now(), 'payout_error' => null]);

            return true;
        });
        if (! $claimed) {
            return false;
        }
        $payment->refresh();

        try {
            $contactId = $this->contact($payment);
        } catch (\Throwable $e) {
            // Rien n'est parti : seul le bénéficiaire n'a pas pu être enregistré.
            return $this->fail($payment, 'Bénéficiaire non enregistré chez Jèko ('.mb_substr($e->getMessage(), 0, 80).'). Rien n’a été envoyé.');
        }

        try {
            $response = $this->http()->post('/partner_api/transfers', [
                'storeId' => config('services.jeko.store_id'),
                'contactId' => $contactId,
                'amountCents' => $payment->amount * 100,
                'currency' => 'XOF',
                'reference' => $payment->reference,
                'description' => mb_substr(($payment->type === PaymentType::Withdrawal ? 'Retrait Sub.ci ' : 'Remboursement Sub.ci ').$payment->reference, 0, 255),
            ]);
        } catch (\Throwable $e) {
            // Réseau coupé pendant l'envoi : on ne sait pas si Jèko l'a reçu → jamais de nouvelle tentative auto.
            return $this->fail($payment, 'Envoi interrompu ('.mb_substr($e->getMessage(), 0, 80).'). Vérifie dans Jèko avant de renvoyer.');
        }

        if (! $response->successful() || ! $response->json('id')) {
            $reason = match ($response->json('code') ?? $response->json('error')) {
                'insufficient_balance' => 'Solde Jèko insuffisant.',
                'contact_not_found' => 'Bénéficiaire introuvable chez Jèko.',
                default => 'Jèko a refusé l’envoi ('.$response->status().' '.mb_substr((string) ($response->json('message') ?? ''), 0, 120).').',
            };

            return $this->fail($payment, $reason);
        }

        $payment->update(['provider_transaction_id' => mb_substr((string) $response->json('id'), 0, 64)]);
        Log::info('Jèko : versement envoyé', ['payment' => $payment->reference, 'transfer' => $response->json('id'), 'status' => $response->json('status')]);

        return $this->settle($payment, (string) $response->json('status'), $response->json('fees.amount'));
    }

    /** Transfert en cours chez Jèko : on relit son statut. */
    private function follow(Payment $payment): bool
    {
        $response = $this->http()->get('/partner_api/transfers/'.rawurlencode($payment->provider_transaction_id));
        if (! $response->successful()) {
            return false;
        }

        return $this->settle($payment, (string) $response->json('status'), $response->json('fees.amount'));
    }

    private function settle(Payment $payment, string $status, mixed $feeCents): bool
    {
        if ($status === 'error') {
            return $this->fail($payment, 'Le transfert a échoué chez l’opérateur. Renvoie-le depuis Jèko ou réessaie.');
        }
        if ($status !== 'success') {
            return false; // en cours : relu à la prochaine minute
        }
        $done = DB::transaction(function () use ($payment, $feeCents) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if ($locked->status !== PaymentStatus::Pending) {
                return false;
            }
            $locked->update(['status' => PaymentStatus::Succeeded, 'confirmed_at' => now()]
                + (is_numeric($feeCents) ? ['provider_fee' => intdiv((int) $feeCents, 100)] : []));

            return true;
        });
        if ($done) {
            $payment->refresh();
            $amount = AdminAlerts::fcfa($payment->amount);
            $payment->user->notify($payment->type === PaymentType::Refund
                ? new AppNotification('pay', 'Remboursement reçu', "{$amount} renvoyés sur ton ".$payment->method->label().'.')
                : new AppNotification('host', 'Retrait envoyé', "{$amount} versés sur ton ".$payment->method->label().'.'));
        }

        return $done;
    }

    private function fail(Payment $payment, string $reason): bool
    {
        $payment->update(['payout_error' => $reason]);
        Log::warning('Jèko : versement non envoyé', ['payment' => $payment->reference, 'reason' => $reason]);
        AdminAlerts::send('payouts', 'Versement auto bloqué · '.AdminAlerts::fcfa($payment->amount), $payment->user->shortName().' · '.$reason, '/payouts', 'payouts');

        return false;
    }

    /** Bénéficiaire Jèko pour ce numéro et cet opérateur (créé s'il n'existe pas). */
    private function contact(Payment $payment): string
    {
        $number = '+225'.$payment->phone;
        $method = config('services.jeko.payout_method_names')[$payment->method->value] ?? $payment->method->value;
        $existing = collect($this->contacts())->first(fn ($c) => ($c['identifier']['number'] ?? null) === $number && ($c['paymentMethod'] ?? null) === $method);
        if ($existing) {
            return (string) $existing['id'];
        }
        $response = $this->http()->post('/partner_api/contacts', [
            'name' => mb_substr($payment->user->shortName().' · Sub.ci', 0, 80),
            'paymentMethod' => $method,
            'identifier' => ['number' => $number],
        ]);
        if (! $response->successful() || ! $response->json('id')) {
            throw new \RuntimeException('bénéficiaire refusé par Jèko ('.$response->status().')');
        }
        Cache::forget('jeko:contacts');
        Cache::forget('jeko:contacts:full');

        return (string) $response->json('id');
    }

    private function contacts(): array
    {
        return Cache::remember('jeko:contacts:full', 300, function () {
            $rows = $this->http()->get('/partner_api/contacts')->json();

            return is_array($rows) ? (array_is_list($rows) ? $rows : (array) ($rows['data'] ?? [])) : [];
        });
    }

    private function http(): PendingRequest
    {
        $c = config('services.jeko');

        return Http::baseUrl($c['base_url'])
            ->withHeaders(['X-API-KEY' => $c['key'], 'X-API-KEY-ID' => $c['key_id']])
            ->acceptJson()->asJson()->timeout(30);
    }
}
