<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CircleResource;
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
 * ou ouvert (ses places libres sont proposées aux autres utilisateurs de Sub.ci).
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

    public function join(Request $request, Circle $circle): CircleResource
    {
        $user = $request->user();
        abort_if($circle->closed_at !== null, 404, 'Ce cercle n’existe plus.');

        $joined = DB::transaction(function () use ($circle, $user) {
            $locked = Circle::whereKey($circle->id)->lockForUpdate()->first();
            if ($locked->hasMember($user)) {
                return false;
            }
            abort_if($locked->freeSeats() === 0, 422, 'Le cercle est complet. Demande à '.$locked->owner->shortName().' d’ajouter une place.');
            $locked->members()->attach($user->id);

            return true;
        });

        if ($joined) {
            $circle->owner->notify(new AppNotification('seat', "{$user->shortName()} a rejoint {$circle->name}",
                $circle->freeSeats() > 0 ? "Encore {$circle->freeSeats()} place(s) libre(s)." : 'Ton cercle est complet.',
                ['label' => 'Voir', 'to' => "/circles/{$circle->code}"]));
        }

        return new CircleResource($circle->fresh()->load(self::RELATIONS));
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
        $circle->members()->detach($member->id);

        return response()->json(['ok' => true]);
    }

    public function close(Request $request, Circle $circle): JsonResponse
    {
        $this->owner($request, $circle);
        $circle->update(['closed_at' => now()]);

        return response()->json(['ok' => true]);
    }

    private function owner(Request $request, Circle $circle): void
    {
        abort_unless($circle->user_id === $request->user()->id && $circle->closed_at === null, 403, 'Seul le créateur du cercle peut faire ça.');
    }
}
