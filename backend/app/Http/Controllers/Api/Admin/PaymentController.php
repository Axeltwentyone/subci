<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Notifications\AppNotification;
use App\Services\JekoLedger;
use App\Services\PaymentService;
use App\Support\PayoutCheck;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Tous les mouvements d'argent + versements manuels (remboursements, retraits). */
class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'type' => ['nullable', Rule::enum(PaymentType::class)],
            'q' => ['nullable', 'string', 'max:80'],
            'userId' => ['nullable', 'integer'],
        ]);

        $payments = Payment::with('user', 'service', 'joinRequest', 'source.user')
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($data['userId'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->whereLike('reference', "%{$term}%")->orWhereLike('provider_reference', "%{$term}%")->orWhereLike('phone', "%{$term}%")
                ->orWhereHas('user', fn ($u) => $u->whereLike('name', "%{$term}%")->orWhereLike('phone', "%{$term}%"))
                // Une référence de paiement retrouve aussi les versements à l'hôte qui en découlent.
                ->orWhereHas('source', fn ($s) => $s->whereLike('reference', "%{$term}%"))))
            ->latest()
            ->paginate(30);

        return response()->json([
            'data' => collect($payments->items())->map(fn ($p) => Presenter::payment($p)),
            'meta' => ['total' => $payments->total(), 'page' => $payments->currentPage(), 'pages' => $payments->lastPage()],
        ]);
    }

    /** À verser à la main : remboursements et retraits des hôtes. */
    /** Détail d'un paiement, avec la répartition (commission, versements à l'hôte). */
    public function show(Payment $payment): JsonResponse
    {
        $payment->load('user', 'service', 'joinRequest', 'source.user');

        return response()->json(['data' => Presenter::payment($payment) + ['split' => Presenter::split($payment)]]);
    }

    public function payouts(): JsonResponse
    {
        $pending = Payment::with('user', 'service')->toPayOut()->oldest()->get();
        $done = Payment::with('user', 'service')->where('status', PaymentStatus::Succeeded)
            ->whereIn('type', [PaymentType::Refund, PaymentType::Withdrawal])->latest('confirmed_at')->limit(20)->get();

        return response()->json([
            'pending' => $pending->map(fn ($p) => Presenter::payment($p)),
            'done' => $done->map(fn ($p) => Presenter::payment($p)),
            'total' => (int) $pending->sum('amount'),
        ]);
    }

    /**
     * Hôtes : chacun peut-il recevoir ses retraits par Jèko ?
     * Moyen de retrait accepté par Jèko, numéro mobile ivoirien cohérent avec l'opérateur,
     * et bénéficiaire déjà enregistré chez Jèko (sinon il sera créé au premier envoi).
     */
    public function hostPayoutChecks(JekoLedger $ledger): JsonResponse
    {
        $contacts = $ledger->contactNumbers();
        $hosts = User::query()
            ->where(fn ($q) => $q->whereHas('hostOffers')->orWhere('balance', '>', 0))
            ->withSum(['payments as escrow' => fn ($q) => $q->escrowed()], 'amount')
            ->withCount(['payments as withdrawals_pending' => fn ($q) => $q->where('type', PaymentType::Withdrawal)->where('status', PaymentStatus::Pending)])
            ->orderByDesc('balance')->get()
            ->map(function (User $u) use ($contacts) {
                $check = PayoutCheck::check($u->payout_method, $u->payout_phone ?? $u->phone);

                return [
                    'id' => $u->id,
                    'name' => $u->shortName(),
                    'phone' => $u->phone,
                    'payoutPhone' => $check['phone'],
                    'ownNumber' => $u->payout_phone === null,
                    'method' => $check['method'],
                    'methodLabel' => \App\Enums\PayMethod::from($check['method'])->label(),
                    'defaulted' => $check['defaulted'],
                    'ok' => $check['ok'],
                    'issues' => $check['issues'],
                    'balance' => (int) $u->balance,
                    'escrow' => (int) $u->escrow,
                    'withdrawalsPending' => (int) $u->withdrawals_pending,
                    'jekoContact' => $contacts === null ? null : in_array('+225'.$check['phone'], $contacts, true),
                ];
            });

        return response()->json([
            'data' => $hosts->sortBy('ok')->values(),
            'summary' => ['total' => $hosts->count(), 'ok' => $hosts->where('ok', true)->count(), 'jeko' => $contacts !== null],
            'delayHours' => (int) config('services.payments.withdrawal_delay_hours'),
        ]);
    }

    /**
     * Relance l'envoi automatique d'un versement bloqué. Seulement si aucun transfert n'a été créé chez Jèko :
     * après une coupure réseau, vérifie d'abord dans le dashboard Jèko que rien n'est parti.
     */
    public function retryAutoPayout(Request $request, Payment $payment, \App\Services\JekoPayouts $payouts): JsonResponse
    {
        abort_unless($payment->status === PaymentStatus::Pending && in_array($payment->type, [PaymentType::Refund, PaymentType::Withdrawal], true), 409, 'Ce versement n’est pas en attente.');
        abort_if($payment->provider_transaction_id !== null, 409, 'Un transfert existe déjà chez Jèko pour ce versement.');
        abort_unless(\App\Services\JekoPayouts::handles($payment), 409, 'Cet opérateur n’est pas en envoi automatique.');
        $payment->update(['payout_attempted_at' => null, 'payout_error' => null]);
        $request->user()->log('payout.retry', $payment);
        $payouts->send($payment->fresh());

        return response()->json(['data' => Presenter::payment($payment->fresh(['user', 'service']))]);
    }

    public function markPaid(Request $request, Payment $payment): JsonResponse
    {
        // fee : frais d'envoi réellement facturés par l'opérateur (sinon estimés dans la trésorerie).
        $data = $request->validate(['note' => ['nullable', 'string', 'max:120'], 'fee' => ['nullable', 'integer', 'min:0']]);
        // Verrou : un double clic ne marque (et ne notifie) qu'une fois.
        $done = DB::transaction(function () use ($payment, $data) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if ($locked->status !== PaymentStatus::Pending || ! in_array($locked->type, [PaymentType::Refund, PaymentType::Withdrawal], true) || $locked->refund_choice === 'pending') {
                return false;
            }
            $locked->update(['status' => PaymentStatus::Succeeded, 'confirmed_at' => now()] + (isset($data['fee']) ? ['provider_fee' => $data['fee']] : []));

            return true;
        });
        abort_unless($done, 409, 'Ce versement n’est pas en attente.');
        $payment->refresh();
        $amount = number_format($payment->amount, 0, ',', ' ').' FCFA';
        $payment->user->notify($payment->type === PaymentType::Refund
            ? new AppNotification('pay', 'Remboursement reçu', "{$amount} renvoyés sur ton ".$payment->method->label().'.')
            : new AppNotification('host', 'Retrait envoyé', "{$amount} versés sur ton ".$payment->method->label().'.'));
        $request->user()->log('payout.paid', $payment, array_filter(['amount' => $payment->amount, 'fee' => $data['fee'] ?? null, 'note' => $data['note'] ?? null], fn ($v) => $v !== null));

        return response()->json(['data' => Presenter::payment($payment->fresh(['user', 'service']))]);
    }

    /** Relit le statut chez la passerelle (paiement resté en attente). */
    public function reconcile(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        abort_unless($payment->type === PaymentType::Subscription, 409, 'Seul un paiement d’abonnement peut être relu.');
        $before = $payment->status->value;
        $payments->refresh($payment, force: true);
        $request->user()->log('payment.reconcile', $payment, ['before' => $before, 'after' => $payment->fresh()->status->value]);

        return response()->json(['data' => Presenter::payment($payment->fresh(['user', 'service', 'joinRequest']))]);
    }
}
