<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Notifications\AppNotification;
use App\Services\PaymentService;
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
        $pending = Payment::with('user', 'service')->where('status', PaymentStatus::Pending)
            ->whereIn('type', [PaymentType::Refund, PaymentType::Withdrawal])->oldest()->get();
        $done = Payment::with('user', 'service')->where('status', PaymentStatus::Succeeded)
            ->whereIn('type', [PaymentType::Refund, PaymentType::Withdrawal])->latest('confirmed_at')->limit(20)->get();

        return response()->json([
            'pending' => $pending->map(fn ($p) => Presenter::payment($p)),
            'done' => $done->map(fn ($p) => Presenter::payment($p)),
            'total' => (int) $pending->sum('amount'),
        ]);
    }

    public function markPaid(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:120']]);
        // Verrou : un double clic ne marque (et ne notifie) qu'une fois.
        $done = DB::transaction(function () use ($payment) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if ($locked->status !== PaymentStatus::Pending || ! in_array($locked->type, [PaymentType::Refund, PaymentType::Withdrawal], true)) {
                return false;
            }
            $locked->update(['status' => PaymentStatus::Succeeded, 'confirmed_at' => now()]);

            return true;
        });
        abort_unless($done, 409, 'Ce versement n’est pas en attente.');
        $payment->refresh();
        $amount = number_format($payment->amount, 0, ',', ' ').' FCFA';
        $payment->user->notify($payment->type === PaymentType::Refund
            ? new AppNotification('pay', 'Remboursement reçu', "{$amount} renvoyés sur ton ".$payment->method->label().'.')
            : new AppNotification('host', 'Retrait envoyé', "{$amount} versés sur ton ".$payment->method->label().'.'));
        $request->user()->log('payout.paid', $payment, array_filter(['amount' => $payment->amount, 'note' => $data['note'] ?? null]));

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
