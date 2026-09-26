<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Support\Totp;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Connexion admin en deux étapes : mot de passe, puis code de l'application
 * d'authentification (Google Authenticator…). Sans double authentification
 * configurée, le compte ne reçoit qu'un jeton « admin-setup » qui sert
 * uniquement à la configurer.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $email = strtolower($data['email']);
        // Par e-mail + IP, et par e-mail seul (attaque répartie sur plusieurs IP).
        $keys = ['admin-login:'.$email.'|'.$request->ip() => 5, 'admin-login-email:'.$email => 10];
        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['email' => 'Trop de tentatives. Réessaie dans '.ceil(RateLimiter::availableIn($key) / 60).' min.']);
            }
        }

        $admin = Admin::where('email', $email)->first();
        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            foreach ($keys as $key => $max) {
                RateLimiter::hit($key, str_contains($key, '|') ? 300 : 3600);
            }
            throw ValidationException::withMessages(['email' => 'E-mail ou mot de passe incorrect.']);
        }
        RateLimiter::clear(array_key_first($keys));

        if ($admin->hasTwoFactor()) {
            // Étape 2 : jeton de défi chiffré, valable 5 min, sans aucun droit.
            return response()->json([
                'twoFactor' => true,
                'challenge' => Crypt::encryptString(json_encode(['id' => $admin->id, 'exp' => now()->addMinutes(5)->timestamp])),
            ]);
        }

        if (config('services.admin.require_2fa')) {
            return response()->json([
                'setup' => true,
                'token' => $admin->createToken('admin-setup', ['admin-setup'], now()->addMinutes(15))->plainTextToken,
                'admin' => self::present($admin),
            ]);
        }

        return $this->grant($admin);
    }

    /** Étape 2 : code à 6 chiffres de l'application d'authentification. */
    public function twoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string'], 'code' => ['required', 'digits:6']]);
        try {
            $payload = json_decode(Crypt::decryptString($data['challenge']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['code' => 'Session expirée. Reconnecte-toi.']);
        }
        abort_if(($payload['exp'] ?? 0) < time(), 422, 'Session expirée. Reconnecte-toi.');
        $admin = Admin::findOrFail($payload['id'] ?? 0);

        $key = 'admin-2fa:'.$admin->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Trop de codes erronés. Réessaie dans '.ceil(RateLimiter::availableIn($key) / 60).' min.']);
        }
        $step = Totp::verify((string) $admin->two_factor_secret, $data['code'], $admin->two_factor_last_step);
        if ($step === null) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['code' => 'Code incorrect.']);
        }
        RateLimiter::clear($key);
        $admin->forceFill(['two_factor_last_step' => $step])->save();

        return $this->grant($admin);
    }

    /** Configuration : nouveau secret à scanner (tant que la double authentification n'est pas active). */
    public function setupTwoFactor(Request $request): JsonResponse
    {
        $admin = $request->user();
        abort_if($admin->hasTwoFactor(), 409, 'La double authentification est déjà active.');
        $secret = Totp::secret();
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        return response()->json(['secret' => $secret, 'uri' => Totp::uri($secret, $admin->email)]);
    }

    /** Confirmation avec un premier code : active la double authentification et ouvre la session complète. */
    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $admin = $request->user();
        abort_if($admin->hasTwoFactor(), 409, 'La double authentification est déjà active.');
        abort_unless($admin->two_factor_secret, 422, 'Lance d’abord la configuration.');

        $key = 'admin-2fa:'.$admin->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Trop de codes erronés. Réessaie dans quelques minutes.']);
        }
        $step = Totp::verify($admin->two_factor_secret, $data['code']);
        if ($step === null) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['code' => 'Code incorrect. Vérifie l’heure de ton téléphone.']);
        }
        $admin->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step])->save();
        $admin->log('2fa.enable');
        $request->user()->currentAccessToken()->delete();

        return $this->grant($admin);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => self::present($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    private function grant(Admin $admin): JsonResponse
    {
        $admin->update(['last_login_at' => now()]);
        $admin->log('login');

        return response()->json([
            // Session admin courte : 12 h.
            'token' => $admin->createToken('admin', ['admin'], now()->addDays(7))->plainTextToken,
            'admin' => self::present($admin),
        ]);
    }

    private static function present(Admin $admin): array
    {
        return ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'twoFactor' => $admin->hasTwoFactor()];
    }
}
