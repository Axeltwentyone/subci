<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CircleResource;
use App\Http\Resources\PaymentResource;
use App\Enums\PayMethod;
use App\Services\CircleService;
use App\Services\PaymentService;
use App\Models\Circle;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Partage : cercles d'amis autour d'un abonnement. Cercle privé (sur invitation, par lien)
 * ou ouvert (ses places libres sont proposées aux autres utilisateurs de Subci).
 */
class CircleController extends Controller
{
    private const RELATIONS = ['service', 'owner', 'members'];

    private const MESSAGES = [
        'seat_price.required_if' => 'Fixe le prix d’une place pour ton cercle ouvert.',
        'seat_price.lte' => 'Le prix d’une place ne peut pas dépasser celui de l’abonnement.',
        'seat_price.max' => 'Le prix d’une place ne peut pas dépasser celui de l’abonnement.',
        'seat_price.min' => 'Le prix d’une place est d’au moins 200 FCFA.',
    ];

    /** Mes cercles, et les cercles ouverts où il reste de la place. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $mine = Circle::active()->whereHas('members', fn ($q) => $q->whereKey($user->id))
            ->with(self::RELATIONS)->latest()->get();
        // ?service=netflix : les offres ouvertes d'un service, affichées sur sa page.
        $service = $request->string('service')->toString();
        $open = Circle::active()->where('is_open', true)
            ->when($service !== '', fn ($q) => $q->whereHas('service', fn ($s) => $s->where('slug', $service)))
            ->whereDoesntHave('members', fn ($q) => $q->whereKey($user->id))
            ->with(self::RELATIONS)->withCount('members')->latest()->limit(50)->get()
            ->filter(fn (Circle $c) => $c->members_count < $c->seats)->values();

        return response()->json([
            'mine' => CircleResource::collection($mine),
            'open' => CircleResource::collection($open),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service' => ['required', 'string', Rule::exists('services', 'slug')->where('is_active', true)],
            'name' => ['required', 'string', 'min:2', 'max:40'],
            'seats' => ['required', 'integer', 'min:2', 'max:'.Circle::MAX_SEATS],
            'price' => ['required', 'integer', 'min:500', 'max:200000'],
            'open' => ['boolean'],
            // Cercle ouvert : le créateur fixe le prix d'une place (au plus le prix de l'abonnement).
            'seat_price' => ['nullable', 'required_if:open,true', 'integer', 'min:200', 'lte:price'],
        ], self::MESSAGES);
        $user = $request->user();
        abort_if(Circle::active()->where('user_id', $user->id)->count() >= 10, 422, 'Tu as déjà 10 cercles. Ferme-en un pour en créer un autre.');

        $circle = DB::transaction(function () use ($data, $user) {
            $circle = Circle::create([
                'user_id' => $user->id,
                'service_id' => Service::where('slug', $data['service'])->value('id'),
                'name' => trim($data['name']),
                'seats' => $data['seats'],
                'price' => $data['price'],
                'is_open' => $data['open'] ?? false,
                'seat_price' => $data['seat_price'] ?? null,
            ]);
            $circle->members()->attach($user->id);

            return $circle;
        });

        return (new CircleResource($circle->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    /** Le code du lien suffit pour voir le cercle (c'est l'invitation). */
    public function show(Circle $circle): CircleResource
    {
        abort_if($circle->closed_at !== null, 404, 'Ce cercle n’existe plus.');

        return new CircleResource($circle->load(self::RELATIONS));
    }

    /**
     * Rejoindre = payer sa part. Gardé pour les anciennes versions de l'app : on renvoie vers le paiement.
     */
    public function join(Request $request, Circle $circle): JsonResponse
    {
        abort_if($circle->closed_at !== null, 404, 'Ce cercle n’existe plus.');

        return response()->json(['message' => 'Pour rejoindre ce cercle, paie ta part ('.number_format(CircleService::total($circle), 0, ',', ' ').' FCFA). Mets l’app à jour si le bouton n’apparaît pas.'], 422);
    }

    /** Payer sa part : nouvelle place, ou le mois suivant pour un membre. */
    public function pay(Request $request, Circle $circle, PaymentService $payments): PaymentResource
    {
        abort_if($circle->closed_at !== null, 404, 'Ce cercle n’existe plus.');
        $data = $request->validate([
            'method' => ['required', Rule::enum(PayMethod::class)],
            'phone' => ['required_unless:method,card', 'nullable', 'digits:10'],
            'returnOrigin' => ['nullable', 'string', 'max:255'],
        ], [
            'phone.digits' => 'Le numéro doit avoir 10 chiffres.',
            'phone.required_unless' => 'Indique ton numéro mobile money.',
        ]);

        $payment = $payments->checkoutCircle($request->user(), $circle, PayMethod::from($data['method']), $data['phone'] ?? null,
            PaymentController::allowedOrigin($data['returnOrigin'] ?? null));

        return new PaymentResource($payment->load('service', 'circle'));
    }

    public function leave(Request $request, Circle $circle): JsonResponse
    {
        abort_if($circle->user_id === $request->user()->id, 422, 'Tu as créé ce cercle : ferme-le plutôt.');
        $circle->members()->detach($request->user()->id);

        return response()->json(['ok' => true]);
    }

    public function update(Request $request, Circle $circle): CircleResource
    {
        $this->owner($request, $circle);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:40'],
            'seats' => ['sometimes', 'integer', 'min:2', 'max:'.Circle::MAX_SEATS],
            'price' => ['sometimes', 'integer', 'min:500', 'max:200000'],
            'open' => ['sometimes', 'boolean'],
            'seat_price' => ['sometimes', 'nullable', 'integer', 'min:200', 'max:'.(int) $request->input('price', $circle->price)],
        ], self::MESSAGES);
        abort_if(isset($data['seats']) && $data['seats'] < $circle->members()->count(), 422, 'Il y a déjà plus de membres que de places.');
        // Parts du mois pas encore versées : places et prix figés (sinon le versement au créateur pourrait être avancé).
        $money = array_intersect_key($data, array_flip(['seats', 'price', 'seat_price', 'open']));
        $changes = array_filter($money, fn ($v, $k) => match ($k) {
            'seats' => $v != $circle->seats, 'price' => $v != $circle->price,
            'seat_price' => $v != $circle->seat_price, 'open' => (bool) $v !== $circle->is_open,
        }, ARRAY_FILTER_USE_BOTH);
        abort_if($changes && \App\Models\Payment::escrowed()->where('circle_id', $circle->id)->exists(), 422,
            'Des parts de ce mois n’ont pas encore été versées : places et prix sont figés jusqu’au versement.');
        $circle->update(array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'seats' => $data['seats'] ?? null,
            'price' => $data['price'] ?? null,
            'is_open' => $data['open'] ?? null,
            'seat_price' => $data['seat_price'] ?? null,
        ], fn ($v) => $v !== null));
        // Ouvert sans prix : on part de la part égale, modifiable ensuite.
        if ($circle->is_open && ! $circle->seat_price) {
            $circle->update(['seat_price' => $circle->share()]);
        }

        return new CircleResource($circle->fresh()->load(self::RELATIONS));
    }

    public function removeMember(Request $request, Circle $circle, User $member): JsonResponse
    {
        $this->owner($request, $circle);
        abort_if($member->id === $circle->user_id, 422, 'Tu ne peux pas te retirer de ton propre cercle.');
        $until = CircleService::paidUntil($circle, $member);
        abort_if($until?->isFuture(), 422, $member->shortName().' a payé sa part jusqu’au '.$until?->translatedFormat('j M').' : sa place lui reste jusque-là.');
        $circle->members()->detach($member->id);

        return response()->json(['ok' => true]);
    }

    public function close(Request $request, Circle $circle): JsonResponse
    {
        $this->owner($request, $circle);
        // Ce qui n'a pas encore été versé au créateur est rendu aux membres.
        $refunded = app(CircleService::class)->close($circle);

        return response()->json(['ok' => true, 'refunded' => $refunded]);
    }

    private function owner(Request $request, Circle $circle): void
    {
        abort_unless($circle->user_id === $request->user()->id && $circle->closed_at === null, 403, 'Seul le créateur du cercle peut faire ça.');
    }
}
