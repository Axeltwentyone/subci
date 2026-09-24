<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Illuminate\Notifications\DatabaseNotification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->data['kind'] ?? 'ok',
            'title' => $this->data['title'] ?? '',
            'body' => $this->data['body'] ?? '',
            'action' => $this->data['action'] ?? null,
            'at' => $this->created_at->toIso8601String(),
            'unread' => $this->read_at === null,
        ];
    }
}
