<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PaymentType;
use App\Http\Controllers\Controller;
use App\Models\JekoTransaction;
use App\Models\MerchantBalance;
use App\Models\Payment;
use App\Models\TreasuryEntry;
use App\Models\TreasuryWithdrawal;
use App\Services\JekoLedger;
use App\Services\Treasury;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Bénéfices et trésorerie Sub.ci : tableau de bord, journal, retraits de bénéfices, charges, rapprochement Jèko. */
class TreasuryController extends Controller
{
    public function __construct(private Treasury $treasury) {}

    public function summary(Request $request): JsonResponse
    {
        [$from, $to, $period] = $this->period($request);

        return response()->json(['preset' => $period] + $this->treasury->summary($from, $to));
    }

    /** Journal chronologique des mouvements (les plus récents d'abord). */
    public function journal(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'kind' => ['nullable', Rule::in(Treasury::JOURNAL)]]);
        $rows = $this->treasury->events($from, $to)
            ->whereIn('kind', $data['kind'] ?? null ? [$data['kind']] : Treasury::JOURNAL)
            ->reverse()->values();
        $page = (int) ($data['page'] ?? 1);
        $per = 50;

        return response()->json([
            'data' => $rows->slice(($page - 1) * $per, $per)->map(fn ($e) => [
                'kind' => $e['kind'],
                'at' => $e['at']->toIso8601String(),
                'label' => $e['label'],
                'cash' => $e['cash'],
                'profit' => $e['profit'],
                'estimated' => $e['estimated'],
                'reference' => $e['reference'],
                'paymentId' => $e['paymentId'],
            ])->values(),
            'meta' => ['total' => $rows->count(), 'page' => $page, 'pages' => max(1, (int) ceil($rows->count() / $per))],
        ]);
    }

    public function withdrawals(): JsonResponse
    {
        return response()->json(['data' => TreasuryWithdrawal::with('admin')->latest('withdrawn_at')->latest('id')->limit(200)->get()->map(fn ($w) => self::withdrawal($w))]);
    }

    public function storeWithdrawal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'fee' => ['required', 'integer', 'min:0', 'lte:amount'],
            'net' => ['nullable', 'integer', 'min:0'],
            'destination' => ['required', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(TreasuryWithdrawal::STATUSES)],
            'withdrawnAt' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $net = $data['net'] ?? $data['amount'] - $data['fee'];
        if ($net > $data['amount'] - $data['fee']) {
            throw ValidationException::withMessages(['net' => 'Le net reçu ne peut pas dépasser le montant moins les frais.']);
        }
        $w = TreasuryWithdrawal::create([
            'admin_id' => $request->user()->id,
            'amount' => $data['amount'],
            'fee' => $data['fee'],
            'net' => $net,
            'destination' => $data['destination'],
            'reference' => $data['reference'] ?? null,
            'status' => $data['status'],
            'withdrawn_at' => $data['withdrawnAt'],
            'decided_at' => $data['status'] === 'pending' ? null : now(),
            'note' => $data['note'] ?? null,
        ]);
        $request->user()->log('treasury.withdrawal', $w, ['amount' => $w->amount, 'fee' => $w->fee, 'status' => $w->status]);

        return response()->json(['data' => self::withdrawal($w->load('admin'))], 201);
    }

    /** Un retrait en attente passe à confirmé ou échoué ; ensuite il ne bouge plus. */
    public function decideWithdrawal(Request $request, TreasuryWithdrawal $withdrawal): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['confirmed', 'failed'])]]);
        $done = DB::transaction(function () use ($withdrawal, $data) {
            $locked = TreasuryWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->first();
            if ($locked->status !== 'pending') {
                return false;
            }
            $locked->update(['status' => $data['status'], 'decided_at' => now()]);

            return true;
        });
        abort_unless($done, 409, 'Ce retrait est déjà confirmé ou échoué.');
        $request->user()->log('treasury.withdrawal.'.$data['status'], $withdrawal);

        return response()->json(['data' => self::withdrawal($withdrawal->fresh('admin'))]);
    }

    public function entries(): JsonResponse
    {
        return response()->json(['data' => TreasuryEntry::with('admin')->latest('occurred_at')->latest('id')->limit(200)->get()->map(fn ($e) => self::entry($e))]);
    }

    /** Charge ou ajustement. Une correction = une écriture inverse (reversesId), jamais une modification. */
    public function storeEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['charge', 'adjustment'])],
            'amount' => ['required', 'integer', 'not_in:0'],
            'label' => ['required', 'string', 'max:160'],
            'fromMerchant' => ['nullable', 'boolean'],
            'occurredAt' => ['required', 'date', 'before_or_equal:now'],
            'reversesId' => ['nullable', 'integer', 'exists:treasury_entries,id'],
        ]);
        $reverses = isset($data['reversesId']) ? TreasuryEntry::find($data['reversesId']) : null;
        if ($reverses) {
            if (TreasuryEntry::where('reverses_id', $reverses->id)->exists()) {
                throw ValidationException::withMessages(['reversesId' => 'Cette écriture a déjà été annulée.']);
            }
            $data['kind'] = $reverses->kind;
            $data['amount'] = -$reverses->amount;
            $data['fromMerchant'] = $reverses->from_merchant;
        } elseif ($data['kind'] === 'charge' && $data['amount'] < 0) {
            throw ValidationException::withMessages(['amount' => 'Une charge est un montant positif.']);
        }
        $entry = TreasuryEntry::create([
            'admin_id' => $request->user()->id,
            'kind' => $data['kind'],
            'amount' => $data['amount'],
            'label' => $reverses ? 'Annulation · '.$reverses->label : $data['label'],
            'from_merchant' => $data['fromMerchant'] ?? true,
            'reverses_id' => $reverses?->id,
            'occurred_at' => $data['occurredAt'],
        ]);
        $request->user()->log('treasury.'.$entry->kind, $entry, ['amount' => $entry->amount]);

        return response()->json(['data' => self::entry($entry->load('admin'))], 201);
    }

    /** Relevé Jèko : soldes relevés et transactions (à classer d'abord). */
    public function jeko(): JsonResponse
    {
        $transactions = JekoTransaction::with('payment', 'withdrawal')
            ->orderByRaw('CASE WHEN payment_id IS NULL AND treasury_withdrawal_id IS NULL AND ignored_reason IS NULL AND status = ? THEN 0 ELSE 1 END', ['success'])
            ->latest('executed_at')->limit(200)->get();

        return response()->json([
            'configured' => JekoLedger::configured(),
            'balances' => MerchantBalance::with('admin')->latest('recorded_at')->limit(30)->get()->map(fn (MerchantBalance $b) => [
                'id' => $b->id, 'amount' => $b->amount, 'source' => $b->source, 'note' => $b->note,
                'at' => $b->recorded_at->toIso8601String(), 'by' => $b->admin?->name,
                'theoretical' => $this->treasury->theoreticalBalance($b->recorded_at),
            ]),
            'transactions' => $transactions->map(fn (JekoTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'status' => $t->status,
                'method' => $t->method,
                'amount' => $t->amount,
                'fee' => $t->fee,
                'counterpart' => $t->counterpart,
                'reference' => $t->jeko_reference,
                'at' => $t->executed_at->toIso8601String(),
                'payment' => $t->payment ? ['id' => $t->payment->id, 'reference' => $t->payment->reference, 'type' => $t->payment->type->value] : null,
                'withdrawalId' => $t->treasury_withdrawal_id,
                'ignored' => $t->ignored_reason,
            ]),
        ]);
    }

    public function sync(Request $request, JekoLedger $ledger): JsonResponse
    {
        $result = $ledger->sync();
        abort_if(isset($result['error']), 502, $result['error'] ?? '');
        abort_unless($result['configured'], 409, 'Jèko n’est pas configuré sur ce serveur : saisis le solde à la main.');
        $request->user()->log('treasury.sync', null, $result);

        return response()->json($result);
    }

    /** Solde communiqué par Jèko (relevé de compte, capture…), quand l'API ne le donne pas. */
    public function storeBalance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer'],
            'recordedAt' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $b = MerchantBalance::create(['admin_id' => $request->user()->id, 'amount' => $data['amount'], 'source' => 'manual', 'note' => $data['note'] ?? null, 'recorded_at' => $data['recordedAt']]);
        $request->user()->log('treasury.balance', $b, ['amount' => $b->amount]);

        return response()->json(['data' => ['id' => $b->id]], 201);
    }

    /** Classe une ligne du relevé Jèko : paiement Sub.ci, retrait de bénéfices, ou hors Sub.ci. */
    public function classify(Request $request, JekoTransaction $transaction, JekoLedger $ledger): JsonResponse
    {
        $data = $request->validate([
            'as' => ['required', Rule::in(['payment', 'withdrawal', 'ignore'])],
            'reference' => ['required_if:as,payment', 'nullable', 'string', 'max:20'],
            'destination' => ['nullable', 'string', 'max:120'],
            'reason' => ['required_if:as,ignore', 'nullable', 'string', 'max:160'],
        ]);
        abort_if($transaction->isClassified(), 409, 'Cette transaction est déjà classée.');
        abort_unless($transaction->status === 'success', 409, 'Seule une transaction réussie se classe.');

        DB::transaction(function () use ($data, $transaction, $ledger, $request) {
            if ($data['as'] === 'ignore') {
                $transaction->update(['ignored_reason' => $data['reason']]);
            } elseif ($data['as'] === 'withdrawal') {
                abort_if($transaction->type === 'payment', 422, 'Un encaissement n’est pas un retrait.');
                $w = TreasuryWithdrawal::create([
                    'admin_id' => $request->user()->id,
                    // Débité du compte : envoyé + frais Jèko.
                    'amount' => $transaction->amount + $transaction->fee,
                    'fee' => $transaction->fee,
                    'net' => $transaction->amount,
                    'destination' => ($data['destination'] ?? null) ?: trim(($transaction->method ?? '').' '.($transaction->counterpart ?? '')),
                    'reference' => $transaction->jeko_reference,
                    'status' => 'confirmed',
                    'withdrawn_at' => $transaction->executed_at,
                    'decided_at' => now(),
                    'jeko_transaction_id' => $transaction->jeko_id,
                ]);
                $transaction->update(['treasury_withdrawal_id' => $w->id]);
            } else {
                $payment = Payment::where('reference', strtoupper(trim($data['reference'])))->first();
                if (! $payment) {
                    throw ValidationException::withMessages(['reference' => 'Aucun paiement avec cette référence.']);
                }
                $expected = $transaction->type === 'payment' ? [PaymentType::Subscription] : [PaymentType::Withdrawal, PaymentType::Refund];
                if (! in_array($payment->type, $expected, true)) {
                    throw ValidationException::withMessages(['reference' => $transaction->type === 'payment' ? 'Choisis un paiement d’abonnement.' : 'Choisis un retrait d’hôte ou un remboursement.']);
                }
                if (JekoTransaction::where('payment_id', $payment->id)->exists()) {
                    throw ValidationException::withMessages(['reference' => 'Ce paiement est déjà rattaché à une autre transaction Jèko.']);
                }
                $ledger->attach($transaction, $payment);
            }
        });
        $request->user()->log('treasury.classify', $transaction, ['as' => $data['as']]);

        return response()->json(['ok' => true]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(['today', 'week', 'month', 'year', 'custom'])],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $now = CarbonImmutable::now();
        $period = $data['period'] ?? 'month';

        return match ($period) {
            'today' => [$now->startOfDay(), $now, $period],
            'week' => [$now->startOfWeek(), $now, $period],
            'year' => [$now->startOfYear(), $now, $period],
            'custom' => [CarbonImmutable::parse($data['from'])->startOfDay(), min($now, CarbonImmutable::parse($data['to'])->endOfDay()), $period],
            default => [$now->startOfMonth(), $now, 'month'],
        };
    }

    private static function withdrawal(TreasuryWithdrawal $w): array
    {
        return [
            'id' => $w->id, 'amount' => $w->amount, 'fee' => $w->fee, 'net' => $w->net, 'destination' => $w->destination,
            'reference' => $w->reference, 'status' => $w->status, 'at' => $w->withdrawn_at->toIso8601String(),
            'decidedAt' => $w->decided_at?->toIso8601String(), 'note' => $w->note, 'by' => $w->admin?->name, 'fromJeko' => (bool) $w->jeko_transaction_id,
        ];
    }

    private static function entry(TreasuryEntry $e): array
    {
        return [
            'id' => $e->id, 'kind' => $e->kind, 'amount' => $e->amount, 'label' => $e->label, 'fromMerchant' => $e->from_merchant,
            'reversesId' => $e->reverses_id, 'at' => $e->occurred_at->toIso8601String(), 'by' => $e->admin?->name,
            'reversed' => TreasuryEntry::where('reverses_id', $e->id)->exists(),
        ];
    }
}
