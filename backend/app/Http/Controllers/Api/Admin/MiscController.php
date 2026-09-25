<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\DisputeStatus;
use App\Enums\JoinStatus;
use App\Enums\OfferStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Dispute;
use App\Models\HostOffer;
use App\Models\JoinRequest;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Availability;
use App\Services\DisputeService;
use App\Services\JoinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Demandes, catalogue, journal, recherche globale. */
class MiscController extends Controller
{
    public function requests(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::enum(JoinStatus::class)]]);
        $status = $data['status'] ?? JoinStatus::Pending->value;

        $rows = JoinRequest::with('user', 'payment', 'offer.service', 'offer.user')
            ->where('status', $status)
            ->when($status === 'pending', fn ($q) => $q->orderBy('expires_at'), fn ($q) => $q->latest('decided_at'))
            ->limit(100)->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => Presenter::request($r)),
            'counts' => JoinRequest::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    /** Refuser à la place de l'hôte (litige, hôte injoignable) : le membre est remboursé. */
    public function declineRequest(Request $request, JoinRequest $joinRequest, JoinService $joins): JsonResponse
    {
        $joins->decline($joinRequest);
        $request->user()->log('request.decline', $joinRequest, ['member' => $joinRequest->user_id]);

        return response()->json(['ok' => true]);
    }

    public function services(Availability $availability): JsonResponse
    {
        $services = $availability->annotate(Service::orderBy('position')->get());
        $month = now()->startOfMonth();

        return response()->json(['data' => $services->map(fn (Service $s) => [
            'id' => $s->id,
            'slug' => $s->slug,
            'name' => $s->name,
            'mono' => $s->mono,
            'color' => $s->color,
            'fg' => $s->fg,
            'category' => $s->category->value,
            'meta' => $s->meta,
            'description' => $s->description,
            'price' => $s->price,
            'fullPrice' => $s->full_price,
            'seats' => $s->seats,
            'isActive' => $s->is_active,
            'isPopular' => $s->is_popular,
            'position' => $s->position,
            'fromPrice' => $s->avail_price,
            'freeSeats' => $s->avail_free,
            'liveOffers' => HostOffer::where('service_id', $s->id)->where('status', OfferStatus::Live)->count(),
            'members' => Subscription::where('service_id', $s->id)->where('status', '!=', SubscriptionStatus::Expired)->count(),
            'gmvMonth' => (int) Payment::where('service_id', $s->id)->where('type', PaymentType::Subscription)->where('status', PaymentStatus::Succeeded)->whereNull('refunded_at')->where('confirmed_at', '>=', $month)->sum('amount'),
        ])]);
    }

    public function updateService(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'meta' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'string', 'max:300'],
            'price' => ['sometimes', 'integer', 'between:200,50000'],
            'fullPrice' => ['sometimes', 'integer', 'between:200,200000'],
            'isActive' => ['sometimes', 'boolean'],
            'isPopular' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'between:0,999'],
        ]);
        $map = ['fullPrice' => 'full_price', 'isActive' => 'is_active', 'isPopular' => 'is_popular'];
        $changes = collect($data)->mapWithKeys(fn ($v, $k) => [$map[$k] ?? $k => $v])->all();
        $before = $service->only(array_keys($changes));
        $service->update($changes);
        $request->user()->log('service.update', $service, ['before' => $before, 'after' => $changes]);

        return response()->json(['ok' => true]);
    }

    public function audit(): JsonResponse
    {
        $rows = AdminAction::with('admin')->latest('created_at')->paginate(50);

        return response()->json([
            'data' => collect($rows->items())->map(fn (AdminAction $a) => [
                'id' => $a->id,
                'admin' => $a->admin->name,
                'action' => $a->action,
                'subjectType' => $a->subject_type,
                'subjectId' => $a->subject_id,
                'meta' => $a->meta,
                'ip' => $a->ip,
                'at' => $a->created_at->toIso8601String(),
            ]),
            'meta' => ['total' => $rows->total(), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage()],
        ]);
    }

    /** Soucis signalés par les membres (gains de l'hôte gelés tant que c'est ouvert). */
    public function disputes(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::enum(DisputeStatus::class)]])['status'] ?? 'open';

        return response()->json([
            'data' => Dispute::with('user', 'offer.user', 'subscription.service')->where('status', $status)
                ->latest()->limit(100)->get()->map(fn (Dispute $d) => Presenter::dispute($d)),
            'counts' => Dispute::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    /** Décision : rembourser le membre (temps non versé à l'hôte) ou clore (gains libérés). */
    public function resolveDispute(Request $request, Dispute $dispute, DisputeService $disputes): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['refund', 'reject'])],
            'note' => ['nullable', 'string', 'max:240'],
        ]);
        $disputes->resolve($dispute, $data['decision'] === 'refund', $data['note'] ?? null, $request->user());

        return response()->json(['data' => Presenter::dispute($dispute->fresh(['user', 'offer.user', 'subscription.service']))]);
    }

    /** Barre de recherche globale : utilisateurs, paiements, offres. */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']])['q']);
        $digits = preg_replace('/\D/', '', $term);

        return response()->json([
            'users' => User::where('name', 'like', "%{$term}%")
                ->when(strlen($digits) >= 3, fn ($q) => $q->orWhere('phone', 'like', "%{$digits}%"))
                ->orWhere('referral_code', 'like', "%{$term}%")
                ->limit(6)->get()->map(fn (User $u) => Presenter::user($u)),
            'payments' => Payment::with('user', 'service')->where('reference', 'like', "%{$term}%")->orWhere('provider_reference', 'like', "%{$term}%")
                ->limit(6)->get()->map(fn (Payment $p) => Presenter::payment($p)),
            'offers' => HostOffer::with('service', 'user', 'members', 'joinRequests')->whereHas('service', fn ($s) => $s->where('name', 'like', "%{$term}%"))
                ->where('status', OfferStatus::Review)->limit(4)->get()->map(fn ($o) => Presenter::offer($o)),
        ]);
    }
}
