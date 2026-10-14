<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\DisputeStatus;
use App\Enums\JoinStatus;
use App\Enums\OfferStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\HostOffer;
use App\Models\JoinRequest;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Vue d'ensemble : argent, activité, ce qui attend une action. */
class OverviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $now = CarbonImmutable::now();
        $current = $now->startOfMonth();
        // Mois affiché (?month=AAAA-MM), par défaut le mois en cours ; jamais dans le futur.
        $asked = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? CarbonImmutable::createFromFormat('!Y-m', $request->query('month'))->startOfMonth() : null;
        $month = $asked && $asked->lte($current) ? $asked : $current;
        $isCurrent = $month->equalTo($current);
        $to = $isCurrent ? $now : $month->endOfMonth();
        $prev = $month->subMonth();
        // Mois en cours : comparé au même nombre de jours du mois d'avant (le 14 octobre → 1er–14 septembre).
        $prevTo = $isCurrent ? $prev->add($month->diff($now)) : $prev->endOfMonth();
        if ($prevTo->gt($prev->endOfMonth())) {
            $prevTo = $prev->endOfMonth();
        }

        $collected = fn ($from, $to) => (int) Payment::where('type', PaymentType::Subscription)->where('status', PaymentStatus::Succeeded)
            ->whereNull('refunded_at')->whereBetween('confirmed_at', [$from, $to])->sum('amount');
        // Gains engagés (versés ou en séquestre), hors gains annulés par un remboursement.
        $earningRows = fn ($from, $to) => Payment::where('type', PaymentType::Earning)->whereIn('status', [PaymentStatus::Succeeded, PaymentStatus::Pending])
            ->whereBetween('created_at', [$from, $to]);
        // Commission réellement gardée sur chaque gain (brut − versé à l'hôte), quel que soit le taux du moment.
        $commissionOf = fn ($from, $to) => (int) $earningRows($from, $to)->sum(DB::raw('COALESCE(gross, amount) - amount'));
        // Frais de service payés par les membres (paiements aboutis, non remboursés).
        $fees = fn ($from, $to) => (int) Payment::where('type', PaymentType::Subscription)->where('status', PaymentStatus::Succeeded)
            ->whereNull('refunded_at')->whereBetween('confirmed_at', [$from, $to])->sum('service_fee');

        $users = fn ($from, $to) => User::whereBetween('created_at', [$from, $to])->count();

        // Courbe par jour : 30 derniers jours pour le mois en cours, sinon les jours du mois choisi.
        $dayFrom = $isCurrent ? $now->subDays(29)->startOfDay() : $month;
        $days = collect(range(0, (int) $dayFrom->startOfDay()->diffInDays($to->startOfDay())))->map(fn ($d) => $dayFrom->addDays($d)->toDateString());
        $gmvByDay = Payment::where('type', PaymentType::Subscription)->where('status', PaymentStatus::Succeeded)->whereNull('refunded_at')
            ->whereBetween('confirmed_at', [$dayFrom, $to])
            ->selectRaw('DATE(confirmed_at) d, SUM(amount) s, COUNT(*) n')->groupBy('d')->get()->keyBy('d');
        $usersByDay = User::whereBetween('created_at', [$dayFrom, $to])
            ->selectRaw('DATE(created_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd');

        // 12 derniers mois jusqu'au mois en cours (requêtes simples : MySQL, SQLite et Postgres).
        $monthly = collect(range(11, 0))->map(function ($i) use ($current, $now, $collected, $commissionOf, $fees, $users) {
            $from = $current->subMonths($i);
            $end = $i === 0 ? $now : $from->endOfMonth();

            return [
                'month' => $from->format('Y-m'),
                'gmv' => $collected($from, $end),
                'revenue' => $commissionOf($from, $end) + $fees($from, $end),
                'users' => $users($from, $end),
            ];
        })->values();
        // Mois sélectionnables : depuis la première inscription.
        $first = User::min('created_at');
        $start = $first ? CarbonImmutable::parse($first)->startOfMonth() : $current;
        $months = collect(range(0, (int) $start->diffInMonths($current)))->map(fn ($i) => $current->subMonths($i)->format('Y-m'))->values();

        $pendingPayouts = Payment::toPayOut();

        return response()->json([
            'period' => [
                'month' => $month->format('Y-m'),
                'current' => $isCurrent,
                'previousTo' => $prevTo->toDateString(),
            ],
            'months' => $months,
            'monthly' => $monthly,
            'kpis' => [
                'gmv' => ['value' => $collected($month, $to), 'previous' => $collected($prev, $prevTo)],
                // Revenu Sub.ci = commission sur les gains des hôtes + frais de service.
                'commission' => ['value' => $commissionOf($month, $to) + $fees($month, $to), 'previous' => $commissionOf($prev, $prevTo) + $fees($prev, $prevTo)],
                'fees' => ['value' => $fees($month, $to), 'previous' => $fees($prev, $prevTo)],
                'members' => ['value' => Subscription::where('status', '!=', SubscriptionStatus::Expired)->distinct('user_id')->count('user_id')],
                'hosts' => ['value' => HostOffer::where('status', OfferStatus::Live)->distinct('user_id')->count('user_id')],
                'newUsers' => ['value' => $users($month, $to), 'previous' => $users($prev, $prevTo)],
            ],
            'money' => [
                // Payé par des membres dont la demande attend la réponse d'un hôte.
                'held' => (int) Payment::whereHas('joinRequest', fn ($q) => $q->where('status', JoinStatus::Pending))->sum('amount'),
                'hostBalances' => (int) User::sum('balance'),
                // Gains d'hôtes en séquestre (versés au solde mois par mois), dont gelés par un souci signalé.
                'escrow' => (int) Payment::escrowed()->sum('amount'),
                'escrowHeld' => (int) Payment::escrowed()->whereNotNull('held_at')->sum('amount'),
                'payoutsPending' => (int) (clone $pendingPayouts)->sum('amount'),
                // Crédits parrainage non encore utilisés (coût futur pour Sub.ci) et parrainages récompensés ce mois.
                'referralCredit' => (int) User::sum('referral_credit'),
                'referralsMonth' => \App\Models\Referral::where('status', 'rewarded')->whereBetween('rewarded_at', [$month, $to])->count(),
            ],
            'todo' => [
                'offersToReview' => HostOffer::where('status', OfferStatus::Review)->count(),
                'disputes' => Dispute::where('status', DisputeStatus::Open)->count(),
                'payouts' => (clone $pendingPayouts)->count(),
                'requests' => JoinRequest::pending()->count(),
                'requestsExpiringSoon' => JoinRequest::pending()->where('expires_at', '<', $now->addHours(3))->count(),
                'paymentsPending' => Payment::where('type', PaymentType::Subscription)->where('status', PaymentStatus::Pending)->count(),
            ],
            'series' => $days->map(fn ($d) => [
                'date' => $d,
                'gmv' => (int) ($gmvByDay[$d]->s ?? 0),
                'payments' => (int) ($gmvByDay[$d]->n ?? 0),
                'users' => (int) ($usersByDay[$d] ?? 0),
            ])->values(),
            'services' => Service::orderBy('position')->get()->map(fn (Service $s) => [
                'id' => $s->slug,
                'name' => $s->name,
                'color' => $s->color,
                'gmv' => (int) Payment::where('service_id', $s->id)->where('type', PaymentType::Subscription)->where('status', PaymentStatus::Succeeded)
                    ->whereNull('refunded_at')->whereBetween('confirmed_at', [$month, $to])->sum('amount'),
                'members' => Subscription::where('service_id', $s->id)->where('status', '!=', SubscriptionStatus::Expired)->count(),
                'offers' => HostOffer::where('service_id', $s->id)->where('status', OfferStatus::Live)->count(),
            ])->sortByDesc('gmv')->values(),
            'activity' => $this->activity(),
        ]);
    }

    /** Derniers évènements, toutes sources confondues. */
    private function activity()
    {
        $payments = Payment::with('user', 'service')->whereIn('status', [PaymentStatus::Succeeded, PaymentStatus::Pending])
            ->whereIn('type', [PaymentType::Subscription, PaymentType::Withdrawal, PaymentType::Refund])
            ->latest()->limit(12)->get()->map(fn (Payment $p) => [
                'kind' => $p->type->value,
                'at' => $p->created_at->toIso8601String(),
                'title' => match ($p->type) {
                    PaymentType::Subscription => $p->user->shortName().' a payé '.($p->service?->name ?? ''),
                    PaymentType::Withdrawal => $p->user->shortName().' demande un retrait',
                    default => 'Remboursement à '.$p->user->shortName(),
                },
                'amount' => $p->amount,
                'status' => $p->status->value,
                'userId' => $p->user_id,
            ]);
        $users = User::latest()->limit(6)->get()->map(fn (User $u) => [
            'kind' => 'signup', 'at' => $u->created_at->toIso8601String(), 'title' => $u->shortName().' s’est inscrit·e', 'amount' => null, 'status' => null, 'userId' => $u->id,
        ]);
        $offers = HostOffer::with('user', 'service')->latest()->limit(6)->get()->map(fn (HostOffer $o) => [
            'kind' => 'offer', 'at' => $o->created_at->toIso8601String(), 'title' => $o->user->shortName().' a publié une offre '.$o->service->name, 'amount' => null, 'status' => $o->status->value, 'userId' => $o->user_id,
        ]);

        return $payments->concat($users)->concat($offers)->sortByDesc('at')->take(15)->values();
    }
}
