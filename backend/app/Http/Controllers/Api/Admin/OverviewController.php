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
        $month = $now->startOfMonth();
        $prev = $month->subMonth();

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

        $gmv = $collected($month, $now);
        $gmvPrev = $collected($prev, $prev->endOfMonth());

        $days = collect(range(29, 0))->map(fn ($d) => $now->subDays($d)->toDateString());
        $gmvByDay = Payment::where('type', PaymentType::Subscription)->where('status', PaymentStatus::Succeeded)->whereNull('refunded_at')
            ->where('confirmed_at', '>=', $now->subDays(29)->startOfDay())
            ->selectRaw('DATE(confirmed_at) d, SUM(amount) s, COUNT(*) n')->groupBy('d')->get()->keyBy('d');
        $usersByDay = User::where('created_at', '>=', $now->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd');

        $pendingPayouts = Payment::where('status', PaymentStatus::Pending)->whereIn('type', [PaymentType::Refund, PaymentType::Withdrawal]);

        return response()->json([
            'kpis' => [
                'gmv' => ['value' => $gmv, 'previous' => $gmvPrev],
                // Revenu Sub.ci = commission sur les gains des hôtes + frais de service.
                'commission' => ['value' => $commissionOf($month, $now) + $fees($month, $now), 'previous' => $commissionOf($prev, $prev->endOfMonth()) + $fees($prev, $prev->endOfMonth())],
                'fees' => ['value' => $fees($month, $now), 'previous' => $fees($prev, $prev->endOfMonth())],
                'members' => ['value' => Subscription::where('status', '!=', SubscriptionStatus::Expired)->distinct('user_id')->count('user_id')],
                'hosts' => ['value' => HostOffer::where('status', OfferStatus::Live)->distinct('user_id')->count('user_id')],
                'newUsers' => ['value' => User::where('created_at', '>=', $month)->count(), 'previous' => User::whereBetween('created_at', [$prev, $prev->endOfMonth()])->count()],
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
                'referralsMonth' => \App\Models\Referral::where('status', 'rewarded')->where('rewarded_at', '>=', $month)->count(),
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
                    ->whereNull('refunded_at')->where('confirmed_at', '>=', $month)->sum('amount'),
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
