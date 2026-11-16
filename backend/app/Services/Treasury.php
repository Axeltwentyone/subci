<?php

namespace App\Services;

use App\Enums\JoinStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\JekoTransaction;
use App\Models\MerchantBalance;
use App\Models\Payment;
use App\Models\PaymentReference;
use App\Models\Referral;
use App\Models\TreasuryEntry;
use App\Models\TreasuryWithdrawal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Bénéfices et trésorerie Sub.ci.
 *
 * Tout part d'une liste de mouvements datés. Chacun a deux effets distincts :
 *  - « profit » : effet sur le bénéfice net Sub.ci (frais de service, commission, frais payés…) ;
 *  - « cash »   : effet sur le compte marchand (encaissement, reversement, retrait…).
 * Les fonds des hôtes et des membres passent dans « cash » sans jamais toucher « profit ».
 * Un retrait de bénéfices est un mouvement de trésorerie : seuls ses frais sont une charge.
 * Chaque commission de la passerelle est comptée une fois : la vraie si Jèko l'a donnée, sinon une estimation (marquée).
 */
class Treasury
{
    /** Mouvements visibles dans le journal (les autres ne touchent que le bénéfice ou sont internes). */
    public const JOURNAL = ['collection', 'duplicate', 'fee_in', 'payout', 'refund', 'fee_out', 'withdrawal', 'withdrawal_fee', 'charge', 'adjustment'];

    public static function feeIn(int $amount): int
    {
        return (int) round($amount * (float) config('services.jeko.fee_in_percent') / 100);
    }

    public static function feeOut(int $amount): int
    {
        return (int) round($amount * (float) config('services.jeko.fee_out_percent') / 100);
    }

    /**
     * Mouvements entre $from (null = depuis le début) et $to.
     *
     * @return Collection<int, array{kind: string, at: CarbonInterface, label: string, cash: int, profit: int, estimated: bool, reference: ?string, paymentId: ?int}>
     */
    public function events(?CarbonInterface $from, CarbonInterface $to): Collection
    {
        $between = fn ($q, string $col) => $from ? $q->whereBetween($col, [$from, $to]) : $q->where($col, '<=', $to);
        $events = collect();
        $push = function (string $kind, CarbonInterface $at, string $label, int $cash, int $profit, bool $estimated = false, ?Payment $p = null) use ($events) {
            $events->push(['kind' => $kind, 'at' => $at, 'label' => $label, 'cash' => $cash, 'profit' => $profit,
                'estimated' => $estimated, 'reference' => $p?->reference, 'paymentId' => $p?->id]);
        };

        // Encaissements clients. Les frais de service sont gagnés une fois l'hôte d'accord et le paiement non remboursé.
        $between(Payment::with('user', 'service:id,name', 'joinRequest:id,payment_id,status')
            ->where('type', PaymentType::Subscription)->where('status', PaymentStatus::Succeeded), 'confirmed_at')
            ->get()->each(function (Payment $p) use ($push) {
                $earned = ! $p->refunded_at && $p->joinRequest?->status !== JoinStatus::Pending ? (int) $p->service_fee : 0;
                $who = $p->user?->shortName() ?? 'Membre';
                $push('collection', $p->confirmed_at, "Encaissement {$who} · ".($p->service?->name ?? $p->label), $p->amount, $earned, false, $p);
                $fee = $p->provider_fee ?? self::feeIn($p->amount);
                $push('fee_in', $p->confirmed_at, 'Commission Jèko · encaissement '.$p->reference, -$fee, -$fee, $p->provider_fee === null, $p);
            });

        // Paiement validé deux fois : le second encaissement est réel (et rendu par un remboursement).
        $between(PaymentReference::with('payment')->whereNotNull('refund_payment_id')
            ->join('payments as r', 'r.id', '=', 'payment_references.refund_payment_id')->select('payment_references.*', 'r.created_at as refund_at'), 'r.created_at')
            ->get()->each(function (PaymentReference $ref) use ($push) {
                $p = $ref->payment;
                $at = CarbonImmutable::parse($ref->refund_at);
                $push('duplicate', $at, 'Encaissement en double '.$p->reference, $p->amount, 0, false, $p);
                $fee = self::feeIn($p->amount);
                $push('fee_in', $at, 'Commission Jèko · double '.$p->reference, -$fee, -$fee, true, $p);
            });

        // Commission Sub.ci sur les gains des hôtes (gain annulé par un remboursement = commission annulée).
        $between(Payment::where('type', PaymentType::Earning)->whereIn('status', [PaymentStatus::Succeeded, PaymentStatus::Pending]), 'created_at')
            ->get(['id', 'reference', 'amount', 'gross', 'created_at'])->each(function (Payment $p) use ($push) {
                $commission = (int) ($p->gross ?? $p->amount) - $p->amount;
                if ($commission > 0) {
                    $push('commission', $p->created_at, 'Commission sur gains hôte', 0, $commission, false, $p);
                }
            });

        // Frais d'envoi facturés aux hôtes sur leurs retraits.
        $between(Payment::where('type', PaymentType::Withdrawal)->where('status', '!=', PaymentStatus::Failed)->where('service_fee', '>', 0), 'created_at')
            ->get(['id', 'reference', 'service_fee', 'created_at'])->each(fn (Payment $p) => $push('host_fee', $p->created_at, 'Frais de retrait facturés à l’hôte', 0, (int) $p->service_fee, false, $p));

        // Reversements envoyés (retraits des hôtes, remboursements en argent) et leurs frais d'envoi.
        $between(Payment::with('user')->whereIn('type', [PaymentType::Withdrawal, PaymentType::Refund])
            ->where('status', PaymentStatus::Succeeded)
            // Remboursement transformé en crédit Sub.ci : rien n'est sorti du compte.
            ->where(fn ($q) => $q->whereNull('refund_choice')->orWhere('refund_choice', '!=', 'credit')), 'confirmed_at')
            ->get()->each(function (Payment $p) use ($push) {
                $who = $p->user?->shortName() ?? '';
                $isRefund = $p->type === PaymentType::Refund;
                $push($isRefund ? 'refund' : 'payout', $p->confirmed_at, ($isRefund ? 'Remboursement ' : 'Reversement hôte ').$who, -$p->amount, 0, false, $p);
                $fee = $p->provider_fee ?? self::feeOut($p->amount);
                $push('fee_out', $p->confirmed_at, 'Frais d’envoi · '.$p->reference, -$fee, -$fee, $p->provider_fee === null, $p);
            });

        // Retraits de bénéfices confirmés : mouvement de trésorerie ; seuls les frais réduisent le bénéfice.
        $between(TreasuryWithdrawal::where('status', 'confirmed'), 'withdrawn_at')->get()->each(function (TreasuryWithdrawal $w) use ($push) {
            $push('withdrawal', $w->withdrawn_at, 'Retrait de bénéfices · '.$w->destination, -($w->amount - $w->fee), 0);
            if ($w->fee > 0) {
                $push('withdrawal_fee', $w->withdrawn_at, 'Frais du retrait de bénéfices', -$w->fee, -$w->fee);
            }
        });

        $between(TreasuryEntry::query(), 'occurred_at')->get()->each(function (TreasuryEntry $e) use ($push) {
            if ($e->kind === 'charge') {
                $push('charge', $e->occurred_at, $e->label, $e->from_merchant ? -$e->amount : 0, -$e->amount);
            } else {
                $push('adjustment', $e->occurred_at, $e->label, $e->amount, 0);
            }
        });

        // Crédits de parrainage offerts : un coût marketing pour Sub.ci (le compte n'est touché qu'à l'utilisation).
        $between(Referral::where('status', 'rewarded')->where('reward', '>', 0), 'rewarded_at')
            ->get(['id', 'reward', 'rewarded_at'])->each(fn (Referral $r) => $push('referral', $r->rewarded_at, 'Crédit de parrainage offert', 0, -(int) $r->reward));

        return $events->sortBy(fn ($e) => $e['at']->getTimestamp())->values();
    }

    /** Bénéfice d'une période, poste par poste. */
    public function profit(Collection $events): array
    {
        $sum = fn (string $kind, string $field = 'profit') => (int) $events->where('kind', $kind)->sum($field);
        $estimated = fn (string $kind) => (int) -$events->where('kind', $kind)->where('estimated', true)->sum('profit');

        $serviceFees = $sum('collection');
        $commission = $sum('commission');
        $hostFees = $sum('host_fee');

        return [
            'gross' => $sum('collection', 'cash') + $sum('duplicate', 'cash'),
            'serviceFees' => $serviceFees,
            'commission' => $commission,
            'hostWithdrawalFees' => $hostFees,
            'revenue' => $serviceFees + $commission + $hostFees,
            'feesIn' => -$sum('fee_in'),
            'feesInEstimated' => $estimated('fee_in'),
            'feesOut' => -$sum('fee_out'),
            'feesOutEstimated' => $estimated('fee_out'),
            'withdrawalFees' => -$sum('withdrawal_fee'),
            'charges' => -$sum('charge'),
            'referral' => -$sum('referral'),
            'net' => (int) $events->sum('profit'),
            // Bénéfices retirés (hors frais, déjà comptés en charge).
            'withdrawn' => -$sum('withdrawal', 'cash'),
            'paidOut' => -$sum('payout', 'cash'),
            'refunded' => -$sum('refund', 'cash'),
        ];
    }

    /** Solde du compte marchand tel que Sub.ci le calcule (jamais présenté comme confirmé). */
    public function theoreticalBalance(CarbonInterface $at): int
    {
        return (int) $this->events(null, $at)->sum('cash');
    }

    /** Ce que Sub.ci doit aujourd'hui (argent qui n'est pas à Sub.ci). */
    public function liabilities(): array
    {
        $toPayOut = Payment::toPayOut();
        $awaitingChoice = (int) Payment::where('type', PaymentType::Refund)->where('status', PaymentStatus::Pending)->where('refund_choice', 'pending')->sum('amount');
        $toSend = (int) (clone $toPayOut)->sum('amount');
        $owners = [
            'hostBalances' => (int) User::sum('balance'),
            'escrow' => (int) Payment::escrowed()->sum('amount'),
            'hostWithdrawalsToSend' => (int) (clone $toPayOut)->where('type', PaymentType::Withdrawal)->sum('amount'),
        ];
        $members = [
            'refundsToSend' => (int) (clone $toPayOut)->where('type', PaymentType::Refund)->sum('amount'),
            'refundsAwaitingChoice' => $awaitingChoice,
            // Payé, en attente de la réponse de l'hôte : rendu en cas de refus.
            'held' => (int) Payment::where('type', PaymentType::Subscription)->where('status', PaymentStatus::Succeeded)->whereNull('refunded_at')
                ->whereHas('joinRequest', fn ($q) => $q->where('status', JoinStatus::Pending))->sum('amount'),
            // Crédits Sub.ci des membres (remboursements en crédit + parrainage) : à honorer au prochain paiement.
            'memberCredit' => (int) User::sum('referral_credit'),
        ];
        $sendingFees = self::feeOut($toSend + $awaitingChoice);
        $pendingWithdrawals = (int) TreasuryWithdrawal::where('status', 'pending')->sum('amount');

        return [
            'owners' => $owners,
            'ownersTotal' => array_sum($owners),
            'members' => $members,
            'membersTotal' => array_sum($members),
            'sendingFees' => $sendingFees,
            'pendingWithdrawals' => $pendingWithdrawals,
            'total' => array_sum($owners) + array_sum($members) + $sendingFees + $pendingWithdrawals,
        ];
    }

    /** Dernier solde réellement relevé (Jèko ou saisi) : null si aucun. */
    public function realBalance(): ?MerchantBalance
    {
        return MerchantBalance::latest('recorded_at')->latest('id')->first();
    }

    /** Tableau de bord complet pour [from, to]. */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $now = CarbonImmutable::now();
        $all = $this->events(null, $now);
        $period = $all->filter(fn ($e) => $e['at'] >= $from && $e['at'] <= $to)->values();
        $profit = $this->profit($period);
        $allTime = $this->profit($all);

        $theoretical = (int) $all->sum('cash');
        $real = $this->realBalance();
        $liabilities = $this->liabilities();
        // Bénéfice gagné et pas encore retiré.
        $profitLeft = $allTime['net'] - $allTime['withdrawn'];
        $available = $real ? $real->amount - $liabilities['total'] : null;
        $realTheoretical = $real ? (int) $all->filter(fn ($e) => $e['at'] <= $real->recorded_at)->sum('cash') : null;

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'profit' => $profit,
            'allTime' => $allTime,
            'cash' => [
                'theoretical' => $theoretical,
                'real' => $real ? [
                    'amount' => $real->amount,
                    'source' => $real->source,
                    'at' => $real->recorded_at->toIso8601String(),
                    // Relevé de plus de 24 h : à actualiser avant de retirer.
                    'stale' => $real->recorded_at->lt($now->subDay()),
                    // Solde calculé au même instant que le relevé, et l'écart entre les deux.
                    'theoreticalThen' => $realTheoretical,
                    'gap' => $real->amount - $realTheoretical,
                ] : null,
            ],
            'liabilities' => $liabilities,
            'available' => [
                // Sur le solde réellement relevé : la seule valeur sûre.
                'confirmed' => $available,
                // Sur le solde calculé : estimation, à confirmer par un relevé.
                'estimated' => $theoretical - $liabilities['total'],
            ],
            'profitLeft' => $profitLeft,
            // Retirable sans toucher à l'argent des hôtes et des membres : jamais sans solde réel.
            'withdrawable' => $available === null ? null : max(0, min($available, $profitLeft)),
            'series' => $this->series($all, $from, $to),
            'reconciliation' => $this->reconciliation($all, $real),
            'config' => [
                'feeInPercent' => (float) config('services.jeko.fee_in_percent'),
                'feeOutPercent' => (float) config('services.jeko.fee_out_percent'),
                'jeko' => JekoLedger::configured(),
            ],
        ];
    }

    /** Courbes : par jour jusqu'à 62 jours, sinon par mois. */
    private function series(Collection $all, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $daily = $from->diffInDays($to) <= 62;
        $start = $daily ? $from->startOfDay() : $from->startOfMonth();
        $buckets = [];
        for ($b = $start; $b <= $to; $b = $daily ? $b->addDay() : $b->addMonth()) {
            $buckets[] = [$b, $daily ? $b->endOfDay() : $b->endOfMonth()];
        }

        $sorted = $all->values();
        $i = 0;
        $balance = 0;
        $count = $sorted->count();

        return array_map(function ($bucket) use ($sorted, &$i, &$balance, $count) {
            [$bFrom, $bTo] = $bucket;
            $in = collect();
            while ($i < $count && $sorted[$i]['at'] <= $bTo) {
                $e = $sorted[$i++];
                $balance += $e['cash'];
                if ($e['at'] >= $bFrom) {
                    $in->push($e);
                }
            }
            $p = $this->profit($in);

            return [
                'date' => $bFrom->toDateString(),
                'gross' => $p['gross'],
                'revenue' => $p['revenue'],
                'net' => $p['net'],
                'fees' => $p['feesIn'] + $p['feesOut'] + $p['withdrawalFees'],
                'withdrawn' => $p['withdrawn'],
                'balance' => $balance,
            ];
        }, $buckets);
    }

    /** Rapprochement : ce que Sub.ci a enregistré face au relevé Jèko. */
    private function reconciliation(Collection $all, ?MerchantBalance $real): array
    {
        $p = $this->profit($all);
        $jeko = JekoTransaction::where('status', 'success')->whereNull('ignored_reason');
        $payIn = (clone $jeko)->where('type', 'payment');
        $out = (clone $jeko)->where('type', '!=', 'payment');
        $lastJeko = MerchantBalance::where('source', 'jeko')->latest('recorded_at')->first();

        return [
            'subci' => [
                'collections' => $p['gross'],
                'fees' => $p['feesIn'] + $p['feesOut'] + $p['withdrawalFees'],
                'feesEstimated' => $p['feesInEstimated'] + $p['feesOutEstimated'],
                'payouts' => $p['paidOut'] + $p['refunded'],
                'withdrawals' => $p['withdrawn'],
                'charges' => -(int) $all->where('kind', 'charge')->sum('cash'),
                'adjustments' => (int) $all->where('kind', 'adjustment')->sum('cash'),
                'balance' => (int) $all->sum('cash'),
            ],
            'jeko' => [
                'synced' => JekoTransaction::exists(),
                // Encaissements bruts = crédité net + commission.
                'collections' => (int) (clone $payIn)->sum('amount') + (int) (clone $payIn)->sum('fee'),
                'feesIn' => (int) (clone $payIn)->sum('fee'),
                'transfers' => (int) (clone $out)->sum('amount'),
                'feesOut' => (int) (clone $out)->sum('fee'),
                'balance' => $lastJeko?->amount,
                'balanceAt' => $lastJeko?->recorded_at?->toIso8601String(),
            ],
            'unclassified' => JekoTransaction::where('status', 'success')->whereNull('payment_id')->whereNull('treasury_withdrawal_id')->whereNull('ignored_reason')->count(),
            'unmatchedPayments' => Payment::where('type', PaymentType::Subscription)->where('status', PaymentStatus::Succeeded)->whereNull('provider_transaction_id')->count(),
        ];
    }
}
