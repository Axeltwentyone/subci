<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return NotificationResource::collection(
            $request->user()->notifications()->whereNull('archived_at')->latest()->limit(100)->get()
        );
    }

    public function read(Request $request, string $id): NotificationResource
    {
        $n = $this->find($request, $id);
        $n->markAsRead();

        return new NotificationResource($n);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Swipe gauche → archiver (annulable via restore). */
    public function archive(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id)->forceFill(['archived_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    public function restore(Request $request, string $id): NotificationResource
    {
        $n = $this->find($request, $id);
        $n->forceFill(['archived_at' => null])->save();

        return new NotificationResource($n);
    }

    private function find(Request $request, string $id): DatabaseNotification
    {
        return $request->user()->notifications()->whereKey($id)->firstOrFail();
    }
}
