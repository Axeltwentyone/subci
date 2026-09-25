<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $key = 'admin-login:'.strtolower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Trop de tentatives. Réessaie dans '.RateLimiter::availableIn($key).' s.']);
        }

        $admin = Admin::where('email', strtolower($data['email']))->first();
        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['email' => 'E-mail ou mot de passe incorrect.']);
        }
        RateLimiter::clear($key);

        $admin->update(['last_login_at' => now()]);
        $admin->log('login');

        return response()->json([
            // Session admin courte : 12 h.
            'token' => $admin->createToken('admin', ['admin'], now()->addHours(12))->plainTextToken,
            'admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $a = $request->user();

        return response()->json(['data' => ['id' => $a->id, 'name' => $a->name, 'email' => $a->email]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }
}
