<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\HostOffer;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServiceSeeder::class);
        $this->admin = Admin::create(['name' => 'Axel', 'email' => 'admin@sub.ci', 'password' => 'un-mot-de-passe-long']);
    }

    private function token(): string
    {
        return $this->postJson('/api/v1/admin/auth/login', ['email' => 'admin@sub.ci', 'password' => 'un-mot-de-passe-long'])->assertOk()->json('token');
    }

    private function offer(string $status = 'review'): HostOffer
    {
        return User::factory()->create()->hostOffers()->create([
            'service_id' => Service::where('slug', 'netflix')->value('id'), 'plan' => 'premium', 'plan_label' => 'Premium · 4 écrans',
            'devices' => ['tv'], 'seats' => 3, 'price' => 2500, 'access_mode' => 'credentials', 'status' => $status,
        ]);
    }

    public function test_login_and_bad_password(): void
    {
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'admin@sub.ci', 'password' => 'faux'])->assertStatus(422);
        $this->withToken($this->token())->getJson('/api/v1/admin/auth/me')->assertOk()->assertJsonPath('data.email', 'admin@sub.ci');
    }

    public function test_member_token_cannot_reach_admin_and_admin_token_cannot_reach_app(): void
    {
        $member = User::factory()->create();
        $memberToken = $member->createToken('pwa')->plainTextToken;
        $this->withToken($memberToken)->getJson('/api/v1/admin/overview')->assertForbidden();

        $adminToken = $this->token();
        // En test, l'utilisateur résolu reste en mémoire d'une requête à l'autre.
        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)->getJson('/api/v1/bootstrap')->assertForbidden();
        $this->getJson('/api/v1/admin/overview')->assertOk()->assertJsonStructure(['kpis', 'money', 'todo', 'series', 'services', 'activity']);
    }

    public function test_approve_and_reject_offers_are_logged_and_notify_host(): void
    {
        $t = $this->token();
        $a = $this->offer();
        $b = $this->offer();

        $this->withToken($t)->postJson("/api/v1/admin/offers/{$a->id}/approve")->assertOk()->assertJsonPath('data.status', 'live');
        $this->withToken($t)->postJson("/api/v1/admin/offers/{$b->id}/reject", ['reason' => ''])->assertStatus(422);
        $this->withToken($t)->postJson("/api/v1/admin/offers/{$b->id}/reject", ['reason' => 'Capture illisible'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame('Offre refusée', $b->user->notifications()->first()->data['title']);
        $this->assertSame(['login', 'offer.approve', 'offer.reject'], $this->admin->actions()->orderBy('id')->pluck('action')->all());
        $this->withToken($t)->postJson("/api/v1/admin/offers/{$a->id}/approve")->assertStatus(409);
    }

    public function test_mark_payout_paid(): void
    {
        $user = User::factory()->create();
        $p = $user->payments()->create(['type' => 'withdrawal', 'status' => 'pending', 'label' => 'Retrait des gains', 'amount' => 5000, 'method' => 'wave', 'phone' => '0700000000']);
        $t = $this->token();

        $this->withToken($t)->getJson('/api/v1/admin/payouts')->assertJsonPath('total', 5000);
        $this->withToken($t)->postJson("/api/v1/admin/payments/{$p->id}/paid")->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->withToken($t)->postJson("/api/v1/admin/payments/{$p->id}/paid")->assertStatus(409);
    }

    public function test_suspended_member_is_logged_out_and_blocked(): void
    {
        $member = User::factory()->create();
        $memberToken = $member->createToken('pwa')->plainTextToken;
        $this->withToken($this->token())->postJson("/api/v1/admin/users/{$member->id}/suspend", ['reason' => 'Fraude au paiement'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($memberToken)->getJson('/api/v1/bootstrap')->assertUnauthorized();
        $this->assertNotNull($member->fresh()->suspended_at);
    }
}
