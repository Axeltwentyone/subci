<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Enums\PayMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\Service;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    /** Historique (paiements aboutis). */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PaymentResource::collection(
            $request->user()->payments()->with('service')->visibleInHistory()->latest('updated_at')->limit(100)->get()
        );
    }

    /** Checkout : durée + moyen → demande envoyée sur le téléphone. */
    public function store(Request $request): PaymentResource
    {
        $data = $request->validate([
            'serviceId' => ['required', 'string', Rule::exists('services', 'slug')->where('is_active', true)],
            'months' => ['required', Rule::in([1, 3, 6])],
            'method' => ['required', Rule::enum(PayMethod::class)],
            'phone' => ['required_unless:method,card', 'nullable', 'digits:10'],
            // Offre choisie par le membre (obligatoire pour rejoindre, inutile pour renouveler).
            'offerId' => ['nullable', 'integer'],
            // Origine de la PWA (retour après la page de paiement), si autorisée.
            'returnOrigin' => ['nullable', 'string', 'max:255'],
            // Apple Music : e-mail de l'identifiant Apple du membre (l'hôte l'invite dans son Partage familial).
            'inviteEmail' => ['nullable', 'email:rfc', 'max:190'],
        ], [
            'phone.digits' => 'Le numéro doit avoir 10 chiffres.',
            'phone.required_unless' => 'Indique ton numéro mobile money.',
        ]);

        $payment = $this->payments->checkout(
            $request->user(),
            Service::where('slug', $data['serviceId'])->firstOrFail(),
            (int) $data['months'],
            PayMethod::from($data['method']),
            $data['phone'] ?? null,
            isset($data['offerId']) ? (int) $data['offerId'] : null,
            self::allowedOrigin($data['returnOrigin'] ?? null),
            isset($data['inviteEmail']) ? strtolower(trim($data['inviteEmail'])) : null,
        );

        return new PaymentResource($payment->load('service', 'hostOffer.user', 'joinRequest'));
    }

    /** Polling de la PWA pendant « Valide sur ton téléphone ». */
    public function show(Request $request, Payment $payment): PaymentResource
    {
        $this->authorizeOwner($request, $payment);

        return new PaymentResource($this->payments->refresh($payment)->load('service', 'hostOffer.user', 'joinRequest'));
    }

    public function resend(Request $request, Payment $payment): PaymentResource
    {
        $this->authorizeOwner($request, $payment);

        return new PaymentResource($this->payments->resend($payment)->load('service', 'hostOffer.user', 'joinRequest'));
    }

    public function cancel(Request $request, Payment $payment): PaymentResource
    {
        $this->authorizeOwner($request, $payment);
        if ($payment->status === PaymentStatus::Pending) {
            $payment->update(['status' => PaymentStatus::Failed]);
        }

        return new PaymentResource($payment->load('service', 'hostOffer.user', 'joinRequest'));
    }

    /** N'accepte qu'une origine déclarée dans FRONTEND_URL (pas de redirection ouverte). */
    private static function allowedOrigin(?string $origin): string
    {
        $origin = rtrim((string) $origin, '/');

        return in_array($origin, config('app.frontend_origins'), true) ? $origin : rtrim(config('app.frontend_url'), '/');
    }

    private function authorizeOwner(Request $request, Payment $payment): void
    {
        abort_unless($payment->user_id === $request->user()->id, 404);
    }
}
