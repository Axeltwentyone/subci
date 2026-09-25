<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\AdminAlerts;
use App\Services\WebPushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Notifications push de l'app d'administration : appareils et alertes choisies. */
class PushController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->state($request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'starts_with:https://', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'in:aes128gcm,aesgcm'],
        ]);

        // Un appareil ne reçoit que les alertes admin (jamais celles d'un membre).
        PushSubscription::updateOrCreate(['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])], [
            'user_id' => null,
            'admin_id' => $request->user()->id,
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json($this->state($request), 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);
        PushSubscription::where('admin_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))
            ->delete();

        return response()->json($this->state($request));
    }

    public function alerts(Request $request): JsonResponse
    {
        $data = $request->validate(['alerts' => ['required', 'array'], 'alerts.*' => ['boolean']]);
        $admin = $request->user();
        $admin->update(['alerts' => array_intersect_key($data['alerts'], AdminAlerts::KINDS) + $admin->alertSettings()]);

        return response()->json($this->state($request));
    }

    /** Envoie une notification d'essai sur les appareils de cet admin. */
    public function test(Request $request, WebPushSender $sender): JsonResponse
    {
        $subs = PushSubscription::where('admin_id', $request->user()->id)->get();
        abort_if($subs->isEmpty(), 422, 'Aucun appareil abonné. Active les notifications d’abord.');
        $sender->send($subs, ['title' => 'Sub.ci Admin', 'body' => 'Les notifications fonctionnent sur cet appareil.', 'url' => '/', 'tag' => 'test']);

        return response()->json(['ok' => true, 'devices' => $subs->count()]);
    }

    private function state(Request $request): array
    {
        $admin = $request->user()->fresh();

        return [
            'publicKey' => config('services.webpush.public_key'),
            'devices' => PushSubscription::where('admin_id', $admin->id)->count(),
            'alerts' => collect(AdminAlerts::KINDS)->map(fn ($k, $kind) => [
                'kind' => $kind,
                'label' => $k[0],
                'on' => $admin->wantsAlert($kind),
            ])->values(),
        ];
    }
}
