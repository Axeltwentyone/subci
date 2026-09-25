<?php

namespace App\Jobs;

use App\Models\Admin;
use App\Models\PushSubscription;
use App\Services\WebPushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAdminAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $kind, public array $payload) {}

    public function handle(WebPushSender $sender): void
    {
        $admins = Admin::all()->filter(fn (Admin $a) => $a->wantsAlert($this->kind))->modelKeys();
        if (! $admins) {
            return;
        }

        $sender->send(
            PushSubscription::whereIn('admin_id', $admins)->get(),
            $this->payload + ['kind' => $this->kind],
            $this->kind === 'payments' ? 'high' : 'normal',
        );
    }
}
