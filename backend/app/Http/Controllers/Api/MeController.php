<?php

namespace App\Http\Controllers\Api;

use App\Enums\PayMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
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
            $user->payout_method = $data['payout']['method'] ?? $user->payout_method;
            $user->payout_phone = $data['payout']['phone'] ?? $user->payout_phone;
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
