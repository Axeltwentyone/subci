<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OfferStatus;
use App\Http\Controllers\Controller;
use App\Models\HostOffer;
use App\Notifications\AppNotification;
use App\Services\SubscriptionSweeper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Modération et suivi des offres des hôtes. */
class OfferController extends Controller
{
    private const RELATIONS = ['service', 'user', 'members', 'joinRequests'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(OfferStatus::class)],
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        $offers = HostOffer::with(self::RELATIONS)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))
                ->orWhereHas('service', fn ($s) => $s->where('name', 'like', "%{$term}%"))))
            // En vérification : les plus anciennes d'abord (file d'attente).
            ->when(($data['status'] ?? null) === 'review', fn ($q) => $q->oldest(), fn ($q) => $q->latest())
            ->paginate(25);

        return response()->json([
            'data' => collect($offers->items())->map(fn ($o) => Presenter::offer($o)),
            'meta' => ['total' => $offers->total(), 'page' => $offers->currentPage(), 'pages' => $offers->lastPage()],
            'counts' => HostOffer::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(HostOffer $offer): JsonResponse
    {
        return response()->json(['data' => Presenter::offer($offer->load(self::RELATIONS))]);
    }

    /** Capture de preuve (fichier privé) : servie uniquement aux admins. */
    public function proof(HostOffer $offer): StreamedResponse
    {
        abort_unless($offer->proof_path && Storage::exists($offer->proof_path), 404, 'Aucune preuve pour cette offre.');

        return Storage::response($offer->proof_path, null, ['Cache-Control' => 'private, no-store']);
    }

    public function approve(Request $request, HostOffer $offer): JsonResponse
    {
        abort_unless($offer->status === OfferStatus::Review, 409, 'Seule une offre en vérification peut être validée.');
        SubscriptionSweeper::approve($offer->load('service', 'user'));
        $request->user()->log('offer.approve', $offer);

        return $this->show($offer->fresh());
    }

    public function reject(Request $request, HostOffer $offer): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:240']], ['reason.required' => 'Indique le motif, il sera envoyé à l’hôte.']);
        abort_unless($offer->status === OfferStatus::Review, 409, 'Seule une offre en vérification peut être refusée.');

        $offer->update(['status' => OfferStatus::Rejected, 'rejection_reason' => $data['reason']]);
        $offer->user->notify(new AppNotification('host', 'Offre refusée', Str::before($offer->service->name, ' ').' : '.$data['reason'], ['label' => 'Voir', 'to' => "/host/offers/{$offer->id}"]));
        $request->user()->log('offer.reject', $offer, ['reason' => $data['reason']]);

        return $this->show($offer->fresh());
    }

    /** Suspendre / réactiver une offre en ligne (signalement, litige). */
    public function toggle(Request $request, HostOffer $offer): JsonResponse
    {
        $next = match ($offer->status) {
            OfferStatus::Live => OfferStatus::Paused,
            OfferStatus::Paused => OfferStatus::Live,
            default => abort(409, 'Seule une offre en ligne ou en pause peut être suspendue ou réactivée.'),
        };
        $offer->update(['status' => $next]);
        $request->user()->log($next === OfferStatus::Paused ? 'offer.suspend' : 'offer.resume', $offer);

        return $this->show($offer->fresh());
    }
}
