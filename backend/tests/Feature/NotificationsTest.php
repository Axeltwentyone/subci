<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\Channels\WebPushChannel;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServiceSeeder::class);
        $this->user = User::factory()->create();
    }

    private function sub(string $endsIn): \App\Models\Subscription
    {
        return $this->user->subscriptions()->create([
            'service_id' => Service::where('slug', 'netflix')->value('id'), 'status' => 'active',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->modify($endsIn), 'pay_method' => 'om',
        ]);
    }

    private function titles(): array
    {
        return $this->user->notifications()->pluck('data')->pluck('title')->all();
    }

    public function test_j3_reminder_is_sent_once(): void
    {
        $this->sub('+60 hours');

        $this->artisan('subscriptions:remind')->assertSuccessful();
        $this->artisan('subscriptions:remind')->assertSuccessful();

        $this->assertSame(['Netflix expire dans 3 jours'], $this->titles());
        $this->assertSame('/checkout/netflix', $this->user->notifications()->first()->data['action']['to']);
    }

    public function test_last_day_sends_only_j1(): void
    {
        $this->sub('+12 hours');
        $this->artisan('subscriptions:remind');
        $this->assertSame(['Netflix expire demain'], $this->titles());
    }

    public function test_far_or_expired_subscriptions_are_ignored(): void
    {
        $this->sub('+10 days');
        $this->sub('-1 day');
        $this->artisan('subscriptions:remind');
        $this->assertSame([], $this->titles());
    }

    public function test_device_can_subscribe_and_unsubscribe(): void
    {
        $this->actingAs($this->user);
        $body = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => 'BPk', 'auth' => 'au'], 'contentEncoding' => 'aes128gcm'];

        $this->postJson('/api/v1/push/subscriptions', ['endpoint' => 'http://insecure'] + $body)->assertStatus(422);
        $this->postJson('/api/v1/push/subscriptions', $body)->assertCreated();
        $this->postJson('/api/v1/push/subscriptions', $body)->assertCreated(); // idempotent
        $this->assertSame(1, PushSubscription::count());

        $this->deleteJson('/api/v1/push/subscriptions', ['endpoint' => $body['endpoint']])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_push_respects_device_and_user_settings(): void
    {
        $due = new AppNotification('due', 'Netflix expire dans 3 jours', '…');
        $seat = new AppNotification('seat', 'Une place s’est libérée', '…');
        $this->assertNotContains(WebPushChannel::class, $due->via($this->user)); // pas d'appareil

        PushSubscription::create(['user_id' => $this->user->id, 'endpoint' => 'https://push.example/x', 'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example/x'), 'public_key' => 'k', 'auth_token' => 'a']);
        $this->assertContains(WebPushChannel::class, $due->via($this->user));

        $this->user->settings = array_merge(User::DEFAULT_SETTINGS, ['notif_seats' => false]);
        $this->assertNotContains(WebPushChannel::class, $seat->via($this->user));
        $this->assertContains('database', $seat->via($this->user));
    }
}
