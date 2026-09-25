<?php

namespace Tests\Feature;

use App\Jobs\SendAdminAlert;
use App\Models\Admin;
use App\Models\HostOffer;
use App\Models\PushSubscription;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminPushTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private const DEVICE = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/admin-device',
        'keys' => ['p256dh' => 'BCLE', 'auth' => 'AUTH'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServiceSeeder::class);
        config(['services.payments.fake_delay' => 0, 'services.webpush.private_key' => 'cle-de-test', 'services.webpush.public_key' => 'publique']);
        $this->admin = Admin::create(['name' => 'Axel', 'email' => 'admin@sub.ci', 'password' => 'un-mot-de-passe-long']);
    }

    public function test_admin_subscribes_device_and_chooses_alerts(): void
    {
        $token = $this->postJson('/api/v1/admin/auth/login', ['email' => 'admin@sub.ci', 'password' => 'un-mot-de-passe-long'])->json('token');
        $this->withToken($token)->getJson('/api/v1/admin/push')->assertOk()
            ->assertJsonPath('devices', 0)
            ->assertJsonPath('alerts.0.kind', 'payments')->assertJsonPath('alerts.0.on', true)
            ->assertJsonPath('alerts.4.kind', 'signups')->assertJsonPath('alerts.4.on', false);

        $this->postJson('/api/v1/admin/push/subscriptions', self::DEVICE)->assertCreated()->assertJsonPath('devices', 1);
        $sub = PushSubscription::firstOrFail();
        $this->assertSame($this->admin->id, $sub->admin_id);
        $this->assertNull($sub->user_id);

        $this->patchJson('/api/v1/admin/push/alerts', ['alerts' => ['payments' => false, 'signups' => true, 'inconnu' => true]])
            ->assertOk()->assertJsonPath('alerts.0.on', false)->assertJsonPath('alerts.4.on', true);
        $this->assertFalse($this->admin->fresh()->wantsAlert('payments'));
        $this->assertArrayNotHasKey('inconnu', $this->admin->fresh()->alerts);

        $this->deleteJson('/api/v1/admin/push/subscriptions', ['endpoint' => self::DEVICE['endpoint']])->assertOk()->assertJsonPath('devices', 0);
    }

    public function test_member_cannot_use_admin_push_and_member_push_never_reaches_admins(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member, 'sanctum')->postJson('/api/v1/admin/push/subscriptions', self::DEVICE)->assertForbidden();

        // Un appareil enregistré par un membre n'est jamais compté pour un admin.
        $this->postJson('/api/v1/push/subscriptions', self::DEVICE)->assertCreated();
        $this->assertSame(0, PushSubscription::whereNotNull('admin_id')->count());
    }

    public function test_paid_membership_alerts_admins(): void
    {
        Queue::fake();
        $host = User::factory()->create(['first_name' => 'Koffi', 'last_name' => 'Yao']);
        $offer = $host->hostOffers()->create([
            'service_id' => Service::where('slug', 'netflix')->value('id'), 'plan' => 'premium', 'plan_label' => 'Premium · 4 écrans',
            'devices' => ['tv'], 'seats' => 3, 'price' => 2400, 'access_mode' => 'credentials', 'status' => 'live', 'approved_at' => now(),
        ]);
        $member = User::factory()->create(['first_name' => 'Awa', 'last_name' => 'Konan']);

        $ref = $this->actingAs($member, 'sanctum')
            ->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $offer->id, 'months' => 1, 'method' => 'wave', 'phone' => '0758421121'])
            ->assertCreated()->json('data.ref');
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'succeeded');

        Queue::assertPushed(SendAdminAlert::class, fn (SendAdminAlert $job) => $job->kind === 'payments'
            && str_contains($job->payload['title'], '2 400 FCFA')
            && str_contains($job->payload['body'], 'Awa K.')
            && $job->payload['url'] === '/payments?q='.$ref);
    }

    public function test_alert_goes_only_to_admins_who_want_it(): void
    {
        $other = Admin::create(['name' => 'Bis', 'email' => 'bis@sub.ci', 'password' => 'un-mot-de-passe-long', 'alerts' => ['payments' => false]]);
        $this->assertTrue($this->admin->wantsAlert('payments'));
        $this->assertFalse($other->wantsAlert('payments'));
        $this->assertTrue($other->wantsAlert('offers'));
        $this->assertFalse($this->admin->wantsAlert('signups'));
    }
}
