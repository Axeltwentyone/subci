<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccessMode;
use App\Enums\Device;
use App\Enums\OfferStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PayMethod;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\HostOfferResource;
use App\Http\Resources\PaymentResource;
use App\Models\HostOffer;
use App\Models\JoinRequest;
use App\Models\OfferMember;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\AdminAlerts;
use App\Services\EarningService;
use App\Services\JoinService;
use App\Support\ImageSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Côté hôte : partager ses places libres et encaisser. */
class HostController extends Controller
{
    public function __construct(private EarningService $earnings) {}

    /** Formules partageables par service (config/plans.php), pour l'écran de création. */
    public function plans(): JsonResponse
    {
        return response()->json(['data' => collect(config('plans'))->map(fn ($plans) => collect($plans)
            ->map(fn ($p, $key) => ['key' => $key] + $p)->values())]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::summary($request->user())]);
    }

    public static function summary(User $user): array
    {
        return [
            'balance' => $user->balance,
            // Gains en séquestre : versés au solde mois par mois.
            'pending' => (int) $user->payments()->escrowed()->sum('amount'),
            'nextRelease' => ($next = $user->payments()->escrowed()->whereNull('held_at')->min('available_at')) ? Carbon::parse($next)->toIso8601String() : null,
            'held' => (int) $user->payments()->escrowed()->whereNotNull('held_at')->sum('amount'),
            'trusted' => $user->isTrustedHost(),
            'holdHours' => $user->holdHours(),
            'withdrawLockedUntil' => self::withdrawLockedUntil($user)?->toIso8601String(),
            'monthGain' => (int) $user->payments()->where('type', PaymentType::Earning)->whereIn('status', [PaymentStatus::Succeeded, PaymentStatus::Pending])->where('created_at', '>=', now()->startOfMonth())->sum('amount'),
            'offers' => HostOfferResource::collection($user->hostOffers()->with(self::RELATIONS)->orderByRaw("status = 'closed'")->latest()->get()),
        ];
    }

    /** Retraits bloqués quelques heures après un changement de numéro de retrait (vol de session). */
    public static function withdrawLockedUntil(User $user): ?CarbonInterface
    {
        $until = $user->payout_changed_at?->copy()->addHours((int) config('services.payments.payout_change_lock_hours'));

        return $until?->isFuture() ? $until : null;
    }

    /** Relations nécessaires à HostOfferResource (demandes en attente comprises). */
    private const RELATIONS = ['service', 'members', 'joinRequests.user', 'joinRequests.payment', 'joinRequests.offer.service', 'joinRequests.offer.user'];

    public function storeOffer(Request $request): HostOfferResource
    {
        $data = $request->validate([
            'serviceId' => ['required', Rule::in(array_keys(config('plans')))],
            'plan' => ['required', 'string'],
            'seats' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'integer', 'between:500,5000'],
            'devices' => ['required', 'array', 'min:1'],
            'devices.*' => [Rule::enum(Device::class)],
            'email' => ['nullable', 'email'],
            'password' => ['nullable', 'string', 'min:4'],
            'proof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'proof.required' => 'Ajoute une capture de ta page « Compte ».',
            'devices.required' => 'Choisis au moins un appareil.',
        ]);

        $plan = config("plans.{$data['serviceId']}.{$data['plan']}")
            ?? throw ValidationException::withMessages(['plan' => 'Formule inconnue pour ce service.']);
        if ($data['seats'] > $plan['max']) {
            throw ValidationException::withMessages(['seats' => "{$plan['max']} places maximum pour cette formule."]);
        }
        if (array_diff($data['devices'], $plan['devices'])) {
            throw ValidationException::withMessages(['devices' => 'Un des appareils n’est pas possible avec cette formule.']);
        }
        if ($plan['mode'] === 'credentials' && (empty($data['email']) || empty($data['password']))) {
            throw ValidationException::withMessages(['email' => 'Indique l’e-mail et le mot de passe du compte à partager.']);
        }

        $service = Service::where('slug', $data['serviceId'])->firstOrFail();
        $offer = $request->user()->hostOffers()->create([
            'service_id' => $service->id,
            'plan' => $data['plan'],
            'plan_label' => $plan['label'],
            'devices' => array_values(array_unique($data['devices'])),
            'quality' => $plan['quality'],
            'seats' => $data['seats'],
            'price' => $data['price'],
            'access_mode' => $plan['mode'],
            'access_email' => $data['email'] ?? null,
            'access_password' => $data['password'] ?? null,
            // Ré-encodée : sans métadonnées (position GPS…) ni contenu caché.
            'proof_path' => ImageSanitizer::store($request->file('proof'), 'proofs'),
            // Invisible dans le catalogue tant que la preuve n'est pas validée.
            'status' => OfferStatus::Review,
        ]);
        AdminAlerts::send('offers', 'Offre à valider',
            $request->user()->shortName()." partage {$service->name} · {$plan['label']}, {$data['seats']} places",
            "/offers?open={$offer->id}", 'offers');

        return new HostOfferResource($offer->load(self::RELATIONS));
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

        $plan = $offer->planConfig();
        $members = $offer->members()->count() + $offer->joinRequests()->pending()->count();
        $data = $request->validate([
            'price' => ['sometimes', 'integer', 'between:500,5000'],
            'seats' => ['sometimes', 'integer', 'min:'.max(1, $members), 'max:'.$plan['max']],
            'devices' => ['sometimes', 'array', 'min:1'],
            'devices.*' => [Rule::in($plan['devices'])],
            'email' => ['sometimes', 'email'],
            'password' => ['sometimes', 'string', 'min:4'],
        ], [
            'seats.min' => $members > 1 ? "Tu as {$members} membres ou demandes : impossible de descendre en dessous." : 'Au moins 1 place.',
            'devices.min' => 'Choisis au moins un appareil.',
            'seats.max' => "{$plan['max']} places maximum pour cette formule.",
        ]);
        if ((isset($data['email']) || isset($data['password'])) && $offer->access_mode !== AccessMode::Credentials) {
            throw ValidationException::withMessages(['email' => 'Cette offre fonctionne par invitation famille, sans identifiants.']);
        }

        $originalEmail = $offer->access_email;
        DB::transaction(function () use ($offer, $data, $originalEmail) {
            $priceChanged = isset($data['price']) && $data['price'] !== $offer->price;
            $accessChanged = (isset($data['email']) && $data['email'] !== $offer->access_email)
                || (isset($data['password']) && $data['password'] !== $offer->access_password);

            $offer->price = $data['price'] ?? $offer->price;
            $offer->seats = $data['seats'] ?? $offer->seats;
            $offer->devices = isset($data['devices']) ? array_values(array_unique($data['devices'])) : $offer->devices;
            $offer->access_email = $data['email'] ?? $offer->access_email;
            $offer->access_password = $data['password'] ?? $offer->access_password;
            $offer->save();

            $short = Str::before($offer->service->name, ' ');
            if (isset($data['email']) && $data['email'] !== $originalEmail) {
                // Autre compte partagé que celui vérifié à la validation : l'équipe est prévenue.
                AdminAlerts::send('offers', "Compte partagé changé · {$short}",
                    $offer->user->shortName()." a remplacé l’e-mail du compte de l’offre #{$offer->id}.", "/offers?open={$offer->id}", 'offers');
            }
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

        return new HostOfferResource($offer->fresh()->load(self::RELATIONS));
    }

    /** Accepter une demande : le membre entre dans le cercle, tu es payé. */
    public function acceptRequest(Request $request, JoinRequest $joinRequest, JoinService $joins): HostOfferResource
    {
        $offer = $joinRequest->offer;
        $this->authorizeOwner($request, $offer);
        $joins->accept($joinRequest);

        return new HostOfferResource($offer->fresh()->load(self::RELATIONS));
    }

    /** Refuser une demande : le membre est remboursé, la place se libère. */
    public function declineRequest(Request $request, JoinRequest $joinRequest, JoinService $joins): HostOfferResource
    {
        $offer = $joinRequest->offer;
        $this->authorizeOwner($request, $offer);
        $joins->decline($joinRequest);

        return new HostOfferResource($offer->fresh()->load(self::RELATIONS));
    }

    /** Retirer un membre : sa place est remise en ligne. */
    public function removeMember(Request $request, HostOffer $offer, OfferMember $member): HostOfferResource
    {
        $this->authorizeOwner($request, $offer);
        abort_unless($member->host_offer_id === $offer->id, 404);

        DB::transaction(function () use ($offer, $member) {
            if ($member->user_id) {
                $short = Str::before($offer->service->name, ' ');
                $offer->subscriptions()->with('user')->where('user_id', $member->user_id)->get()->each(function ($sub) use ($short) {
                    // Les mois pas encore versés à l'hôte sont rendus au membre ; l'accès s'arrête là où l'hôte a été payé.
                    [$refunded, $paidUntil] = $this->earnings->refundUnreleased($sub, "{$short} · retiré par l’hôte");
                    $ends = $paidUntil ? ($paidUntil->isPast() ? now() : $paidUntil) : $sub->ends_at;
                    $sub->update(['host_offer_id' => null, 'auto_renew' => false, 'ends_at' => $ends->min($sub->ends_at)]);
                    $sub->user->notify(new AppNotification('due', "Tu as été retiré·e de {$short}",
                        $refunded > 0
                            ? EarningService::fcfa($refunded).' te sont remboursés pour le temps restant.'
                            : 'Ton accès reste actif jusqu’au '.$sub->ends_at->translatedFormat('j M').'.',
                        ['label' => 'Voir les offres', 'to' => '/service/'.$sub->service->slug]));
                });
                // Compté dans la fiabilité montrée aux hôtes.
                User::whereKey($member->user_id)->increment('removals_count');
            }
            $member->delete();
        });

        return new HostOfferResource($offer->fresh()->load(self::RELATIONS));
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

        return new HostOfferResource($offer->fresh()->load(self::RELATIONS));
    }

    private function authorizeOwner(Request $request, HostOffer $offer): void
    {
        abort_unless($offer->user_id === $request->user()->id, 404);
    }

    /**
     * Offre famille : l'hôte envoie l'invitation à un membre accepté.
     * - « link » (Spotify, YouTube) : lien d'invitation du service, vérifié, transmis au membre ;
     * - « email » (Apple Music) : l'hôte a invité l'identifiant Apple du membre depuis son téléphone.
     */
    public function inviteMember(Request $request, HostOffer $offer, OfferMember $member): HostOfferResource
    {
        $this->authorizeOwner($request, $offer);
        abort_unless($member->host_offer_id === $offer->id && $member->user_id, 404);
        $type = $offer->inviteType();
        abort_unless($type !== null, 409, 'Cette offre fonctionne avec des identifiants partagés, pas par invitation.');

        $data = $request->validate([
            'link' => [$type === 'link' ? 'required' : 'prohibited', 'string', 'max:500'],
        ], ['link.required' => 'Colle le lien d’invitation créé depuis ton compte.']);
        if ($type === 'link' && ! $offer->acceptsInviteLink(trim($data['link']))) {
            throw ValidationException::withMessages(['link' => 'Ce lien ne vient pas de '.$offer->service->name.'. Copie le lien d’invitation depuis la page famille de ton compte.']);
        }

        DB::transaction(function () use ($offer, $member, $type, $data) {
            $sub = $offer->subscriptions()->with('user', 'service')->where('user_id', $member->user_id)->latest('ends_at')->firstOrFail();
            $sub->update([
                'invite_link' => $type === 'link' ? trim($data['link']) : null,
                'invite_sent_at' => now(),
                'status' => SubscriptionStatus::Active,
                'profile_label' => 'Invitation famille envoyée',
            ]);
            $member->update(['invite_pending' => false]);

            $short = Str::before($offer->service->name, ' ');
            $sub->user->notify(new AppNotification('ok', "Ton invitation {$short} est arrivée",
                $type === 'link'
                    ? 'Ouvre le lien dans ton coffre pour rejoindre la famille avec ton propre compte.'
                    : 'Accepte l’invitation sur ton iPhone : Réglages → ton nom → Partage familial.',
                ['label' => 'Rejoindre', 'to' => "/subs/{$sub->id}"]));
        });

        return new HostOfferResource($offer->fresh()->load(self::RELATIONS));
    }

    /** Retrait du solde vers le compte mobile money (frais 0, reçu ~5 min). */
    public function withdraw(Request $request): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:500']]);

        $payment = DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->first();
            if ($until = self::withdrawLockedUntil($user)) {
                throw ValidationException::withMessages(['amount' => 'Numéro de retrait modifié récemment : par sécurité, retrait possible à partir du '.$until->translatedFormat('j M à H\hi').'.']);
            }
            if ($data['amount'] > $user->balance) {
                throw ValidationException::withMessages(['amount' => 'Montant supérieur à ton solde.']);
            }
            $user->decrement('balance', $data['amount']);
            $method = $user->payout_method ?? PayMethod::Wave;
            // Pas d'API de versement chez la passerelle : retrait traité à la main (payouts:*).
            $manual = (bool) config('services.payments.manual_payouts');
            $payment = $user->payments()->create([
                'type' => PaymentType::Withdrawal,
                'status' => $manual ? PaymentStatus::Pending : PaymentStatus::Succeeded,
                'label' => 'Retrait des gains',
                'amount' => $data['amount'],
                'method' => $method,
                'phone' => $user->payout_phone ?? $user->phone,
                'confirmed_at' => $manual ? null : now(),
            ]);
            $amount = number_format($data['amount'], 0, ',', ' ').' FCFA';
            if ($manual) {
                AdminAlerts::send('payouts', "Retrait à verser · {$amount}",
                    $user->shortName().' · '.$method->label().' '.$payment->phone, '/payouts', 'payouts');
            }
            $user->notify($manual
                ? new AppNotification('host', 'Retrait demandé', "{$amount} vers ".$method->label().', reçu sous 48 h.')
                : new AppNotification('host', 'Retrait envoyé', "{$amount} vers ".$method->label()));

            return $payment;
        });

        return response()->json([
            'payment' => new PaymentResource($payment),
            'host' => self::summary($request->user()->fresh()),
        ]);
    }
}
