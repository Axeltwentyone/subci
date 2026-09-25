<?php

namespace App\Http\Controllers\Api;

use App\Enums\PayMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\OtpService;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MeController extends Controller
{
    public function __construct(private OtpService $otp) {}

    /** Envoie un code au numéro du compte avant de changer le numéro de retrait. */
    public function payoutCode(Request $request): JsonResponse
    {
        $code = $this->otp->send($request->user()->phone, 'payout');

        return response()->json([
            'sent' => true,
            'ttl' => config('services.otp.ttl'),
            'debugCode' => config('services.otp.expose_code') ? $code : null,
        ]);
    }

    /** Saisir le code de parrainage d'un ami (avant le premier abonnement). */
    public function referral(Request $request, ReferralService $referrals): UserResource
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $referrals->apply($request->user(), $data['code']);

        return new UserResource($request->user()->fresh());
    }

    /** « Déconnecter mes autres appareils » (téléphone perdu, session volée). */
    public function logoutOthers(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();
        $count = $request->user()->tokens()->where('id', '!=', $current?->id)->delete();

        return response()->json(['ok' => true, 'revoked' => $count]);
    }

    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(Request $request): UserResource
    {
        $data = $request->validate([
            // Lettres (accents compris), espaces, tirets, apostrophes.
            'firstName' => ['required_with:lastName', 'string', 'max:40', "regex:/^[\\pL][\\pL\\s'’-]*$/u"],
            'lastName' => ['required_with:firstName', 'string', 'max:40', "regex:/^[\\pL][\\pL\\s'’-]*$/u"],
            'payout.method' => ['sometimes', Rule::enum(PayMethod::class)->except(PayMethod::Card)],
            'payout.phone' => ['sometimes', 'digits:10'],
            // Changer où partent les gains exige un code SMS envoyé au numéro du compte.
            'payout.code' => ['required_with:payout', 'digits:6'],
            'settings' => ['sometimes', 'array'],
            'settings.notifDue' => ['boolean'],
            'settings.notifSeats' => ['boolean'],
            'settings.notifPromo' => ['boolean'],
            'settings.biometric' => ['boolean'],
            'settings.hideAccess' => [Rule::in(['always', 'never'])],
            'settings.dataSaver' => [Rule::in(['auto', 'on', 'off'])],
        ]);

        $user = $request->user();
        if (isset($data['firstName'])) {
            $user->setNames(self::clean($data['firstName']), self::clean($data['lastName']));
        }
        if (isset($data['payout'])) {
            if (! $this->otp->verify($user->phone, $data['payout']['code'], 'payout')) {
                throw ValidationException::withMessages(['payout.code' => 'Code incorrect ou expiré.']);
            }
            $method = isset($data['payout']['method']) ? PayMethod::from($data['payout']['method']) : $user->payout_method;
            $phone = $data['payout']['phone'] ?? $user->payout_phone;
            if ($method !== $user->payout_method || $phone !== $user->payout_phone) {
                $user->payout_method = $method;
                $user->payout_phone = $phone;
                // Retraits bloqués quelques heures : si ce n'était pas toi, tu as le temps de réagir.
                $user->payout_changed_at = now();
                $user->notify(new AppNotification('host', 'Numéro de retrait modifié',
                    'Tes gains iront sur '.$method?->label().' · '.$phone.'. Pas toi ? Contacte le support tout de suite.'));
            }
        }
        if (isset($data['settings'])) {
            $map = ['notifDue' => 'notif_due', 'notifSeats' => 'notif_seats', 'notifPromo' => 'notif_promo', 'biometric' => 'biometric', 'hideAccess' => 'hide_access', 'dataSaver' => 'data_saver'];
            $settings = array_merge(User::DEFAULT_SETTINGS, $user->settings ?? []);
            foreach ($data['settings'] as $k => $v) {
                $settings[$map[$k]] = $v;
            }
            $user->settings = $settings;
        }
        $user->save();

        return new UserResource($user);
    }

    /** « aya  » → « Aya », « KONÉ » → « Koné », « n'guessan » → « N'Guessan ». */
    private static function clean(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value));

        return preg_replace_callback("/(^|[\\s'’-])(\\pL)/u", fn ($m) => $m[1].mb_strtoupper($m[2]), mb_strtolower($value));
    }
}
