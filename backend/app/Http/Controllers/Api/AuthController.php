<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Connexion = inscription : numéro + code SMS, pas de mot de passe. */
class AuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function sendCode(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'digits:10']], [
            'phone.digits' => 'Le numéro doit avoir 10 chiffres.',
        ]);

        $code = $this->otp->send($data['phone']);

        return response()->json([
            'sent' => true,
            'ttl' => config('services.otp.ttl'),
            'debugCode' => config('services.otp.expose_code') ? $code : null,
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'digits:10'],
            'code' => ['required', 'digits:6'],
        ]);

        if (! $this->otp->verify($data['phone'], $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Code incorrect ou expiré.']);
        }

        $user = User::firstOrCreate(['phone' => $data['phone']]);
        $user->forceFill(['phone_verified_at' => now()])->save();

        return response()->json([
            'token' => $user->createToken('pwa')->plainTextToken,
            'user' => new UserResource($user),
            'isNew' => $user->wasRecentlyCreated,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }
}
