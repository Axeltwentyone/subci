<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccessMode;
use App\Enums\OfferStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\HostOfferResource;
use App\Http\Resources\PaymentResource;
use App\Models\HostOffer;
use App\Models\OfferMember;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Côté hôte : partager ses places libres et encaisser. */
class HostController extends Controller
{
    /** Formules partageables (identique à HOST_PLANS côté PWA). */
    public const PLANS = [
        'netflix' => ['label' => 'Premium · 4 écrans', 'max' => 3],
        'spotify' => ['label' => 'Famille · 6 comptes', 'max' => 5],
        'youtube' => ['label' => 'Famille · 6 comptes', 'max' => 5],
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::summary($request->user())]);
    }

    public static function summary(User $user): array
    {
        return [
            'balance' => $user->balance,
            'monthGain' => (int) $user->payments()->where('type', PaymentType::Earning)->where('status', PaymentStatus::Succeeded)->where('created_at', '>=', now()->startOfMonth())->sum('amount'),
            'offers' => HostOfferResource::collection($user->hostOffers()->with('service', 'members')->orderByRaw("status = 'closed'")->latest()->get()),
        ];
    }

    public function storeOffer(Request $request): HostOfferResource
    {
        $data = $request->validate([
            'serviceId' => ['required', Rule::in(array_keys(self::PLANS))],
            'seats' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'integer', 'between:500,5000'],
            'mode' => ['required', Rule::enum(AccessMode::class)],
            'email' => ['required_if:mode,credentials', 'nullable', 'email'],
            'password' => ['required_if:mode,credentials', 'nullable', 'string', 'min:4'],
            'proof' => ['required', 'image', 'max:5120'],
        ], [
            'proof.required' => 'Ajoute une capture de ta page « Compte ».',
        ]);

        $plan = self::PLANS[$data['serviceId']];
        if ($data['seats'] > $plan['max']) {
            throw ValidationException::withMessages(['seats' => "{$plan['max']} places maximum pour cette formule."]);
        }

        $service = Service::where('slug', $data['serviceId'])->firstOrFail();
        $offer = $request->user()->hostOffers()->create([
                'service_id' => $service->id,
                'plan_label' => $plan['label'],
                'seats' => $data['seats'],
                'price' => $data['price'],
                'access_mode' => $data['mode'],
                'access_email' => $data['email'] ?? null,
                'access_password' => $data['password'] ?? null,
                'proof_path' => $request->file('proof')->store('proofs'),
                // Invisible dans le catalogue tant que la preuve n'est pas validée.
                'status' => OfferStatus::Review,
            ]);

        return new HostOfferResource($offer->load('service', 'members'));
    }

    /**
     * Modifier une offre : prix, places, identifiants.
     * - Prix : s'applique au prochain renouvellement des membres actuels.
     * - Places : jamais moins que les membres actuels ni plus que la formule.
     * - Identifiants : mis à jour dans le coffre des membres rattachés, qui sont prévenus.
     */
    public function updateOffer(Request $request, HostOffer $offer): HostOfferResource
    {
        $this->authorizeOwner($request, $offer);
        abort_if($offer->status === OfferStatus::Closed, 409, 'Cette offre est arrêtée.');

        $plan = self::PLANS[$offer->service->slug] ?? ['max' => $offer->seats];
        $members = $offer->members()->count();
        $data = $request->validate([
            'price' => ['sometimes', 'integer', 'between:500,5000'],
            'seats' => ['sometimes', 'integer', 'min:'.max(1, $members), 'max:'.$plan['max']],
            'email' => ['sometimes', 'email'],
            'password' => ['sometimes', 'string', 'min:4'],
        ], [
            'seats.min' => $members > 1 ? "Tu as {$members} membres : impossible de descendre en dessous." : 'Au moins 1 place.',
            'seats.max' => "{$plan['max']} places maximum pour cette formule.",
        ]);
        if ((isset($data['email']) || isset($data['password'])) && $offer->access_mode !== AccessMode::Credentials) {
            throw ValidationException::withMessages(['email' => 'Cette offre fonctionne par invitation famille, sans identifiants.']);
        }

        DB::transaction(function () use ($offer, $data) {
            $priceChanged = isset($data['price']) && $data['price'] !== $offer->price;
            $accessChanged = (isset($data['email']) && $data['email'] !== $offer->access_email)
                || (isset($data['password']) && $data['password'] !== $offer->access_password);

            $offer->price = $data['price'] ?? $offer->price;
            $offer->seats = $data['seats'] ?? $offer->seats;
            $offer->access_email = $data['email'] ?? $offer->access_email;
            $offer->access_password = $data['password'] ?? $offer->access_password;
            $offer->save();

            $short = Str::before($offer->service->name, ' ');
            if ($accessChanged) {
                $offer->subscriptions()->with('user')->get()->each(function ($sub) use ($offer, $short) {
                    $sub->update(['access_email' => $offer->access_email, 'access_password' => $offer->access_password]);
                    $sub->user->notify(new AppNotification('ok', "Nouveaux accès {$short}", 'Ton hôte a mis à jour les identifiants. Ils sont dans ton coffre.', ['label' => 'Voir', 'to' => "/subs/{$sub->id}"]));
                });
            }
            if ($priceChanged) {
                $offer->subscriptions()->with('user')->get()->each(fn ($sub) => $sub->user->notify(new AppNotification(
                    'pay', "Nouveau prix {$short}", number_format($offer->price, 0, ',', ' ').' FCFA / mois à partir de ton prochain renouvellement.',
                )));
            }
        });

        return new HostOfferResource($offer->fresh()->load('service', 'members'));
    }

    /** Retirer un membre : sa place est remise en ligne. */
    public function removeMember(Request $request, HostOffer $offer, OfferMember $member): HostOfferResource
    {
        $this->authorizeOwner($request, $offer);
        abort_unless($member->host_offer_id === $offer->id, 404);

        DB::transaction(function () use ($offer, $member) {
            // Le membre garde son accès jusqu'à l'échéance, sans renouvellement dans ce groupe.
            if ($member->user_id) {
                $offer->subscriptions()->where('user_id', $member->user_id)->update(['host_offer_id' => null, 'auto_renew' => false]);
            }
            $member->delete();
        });

        return new HostOfferResource($offer->fresh()->load('service', 'members'));
    }

    /** pause | resume | close */
    public function setStatus(Request $request, HostOffer $offer, string $action): HostOfferResource
    {
        $this->authorizeOwner($request, $offer);
        abort_if($offer->status === OfferStatus::Closed, 409, 'Cette offre est déjà arrêtée.');

        $status = match ($action) {
            'pause' => OfferStatus::Paused,
            'resume' => OfferStatus::Live,
            'close' => OfferStatus::Closed,
            default => abort(404),
        };

        DB::transaction(function () use ($offer, $status) {
            // Pas de pause / reprise avant validation : sinon « reprendre » contournerait la vérification.
            abort_if($offer->status === OfferStatus::Review && $status !== OfferStatus::Closed, 409, 'Ton offre est encore en vérification.');
            $offer->update(['status' => $status]);
            if ($status === OfferStatus::Closed) {
                // Les membres gardent l'accès jusqu'à leur échéance, sans renouvellement.
                $short = Str::before($offer->service->name, ' ');
                $offer->subscriptions()->with('user')->get()->each(function ($sub) use ($short) {
                    $sub->update(['auto_renew' => false]);
                    $sub->user->notify(new AppNotification('due', "{$short} : ton hôte arrête le partage", 'Ton accès reste actif jusqu’à l’échéance. On te propose un autre groupe.', ['label' => 'Voir', 'to' => '/service/'.$sub->service->slug]));
                });
            }
        });

        return new HostOfferResource($offer->fresh()->load('service', 'members'));
    }

    private function authorizeOwner(Request $request, HostOffer $offer): void
    {
        abort_unless($offer->user_id === $request->user()->id, 404);
    }

    public function invite(Request $request, HostOffer $offer): HostOfferResource
    {
        $this->authorizeOwner($request, $offer);

        DB::transaction(function () use ($offer) {
            $pending = $offer->members()->where('invite_pending', true)->pluck('user_id')->filter();
            $offer->members()->where('invite_pending', true)->update(['invite_pending' => false]);

            // Invitation envoyée : l'accès des membres concernés devient actif.
            $short = Str::before($offer->service->name, ' ');
            $offer->subscriptions()->with('user')->whereIn('user_id', $pending)->where('status', SubscriptionStatus::Pending)->get()
                ->each(function ($sub) use ($short) {
                    $sub->update(['status' => SubscriptionStatus::Active, 'profile_label' => 'Invitation famille acceptée']);
                    $sub->user->notify(new AppNotification('ok', "{$short} est activé", 'Accepte l’invitation famille reçue par e-mail.', ['label' => 'Voir', 'to' => "/subs/{$sub->id}"]));
                });
        });

        return new HostOfferResource($offer->load('service', 'members'));
    }

    /** Retrait du solde vers le compte mobile money (frais 0, reçu ~5 min). */
    public function withdraw(Request $request): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:500']]);

        $payment = DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->first();
            if ($data['amount'] > $user->balance) {
                throw ValidationException::withMessages(['amount' => 'Montant supérieur à ton solde.']);
            }
            $user->decrement('balance', $data['amount']);
            $method = $user->payout_method ?? \App\Enums\PayMethod::Wave;
            $payment = $user->payments()->create([
                'type' => PaymentType::Withdrawal,
                'status' => PaymentStatus::Succeeded,
                'label' => 'Retrait des gains',
                'amount' => $data['amount'],
                'method' => $method,
                'phone' => $user->payout_phone ?? $user->phone,
                'confirmed_at' => now(),
            ]);
            $user->notify(new AppNotification('host', 'Retrait envoyé', number_format($data['amount'], 0, ',', ' ').' FCFA vers '.$method->label()));

            return $payment;
        });

        return response()->json([
            'payment' => new PaymentResource($payment),
            'host' => self::summary($request->user()->fresh()),
        ]);
    }
}
