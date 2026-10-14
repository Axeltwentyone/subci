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

    public function test_overview_by_month_compares_the_same_number_of_days(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-14 12:00'));
        $user = User::factory()->create(['created_at' => '2026-08-20']);
        $pay = fn (int $amount, string $at) => $user->payments()->create(['type' => 'subscription', 'status' => 'succeeded', 'label' => 'x', 'amount' => $amount, 'months' => 1, 'method' => 'wave'])
            ->forceFill(['confirmed_at' => $at])->save();
        $pay(1000, '2026-09-05 10:00');
        $pay(2000, '2026-09-20 10:00'); // après le 14 : hors comparaison du mois en cours
        $pay(500, '2026-10-03 10:00');
        $t = $this->token();

        $this->withToken($t)->getJson('/api/v1/admin/overview')->assertOk()
            ->assertJsonPath('period.month', '2026-10')->assertJsonPath('period.current', true)->assertJsonPath('period.previousTo', '2026-09-14')
            ->assertJsonPath('kpis.gmv.value', 500)->assertJsonPath('kpis.gmv.previous', 1000)
            ->assertJsonPath('months', ['2026-10', '2026-09', '2026-08'])
            ->assertJsonPath('monthly.10', ['month' => '2026-09', 'gmv' => 3000, 'revenue' => 0, 'users' => 0])
            ->assertJsonCount(12, 'monthly')->assertJsonCount(30, 'series');

        // Mois passé : le mois entier, comparé au mois d'avant entier, une barre par jour du mois.
        $this->withToken($t)->getJson('/api/v1/admin/overview?month=2026-09')->assertOk()
            ->assertJsonPath('period.current', false)->assertJsonPath('kpis.gmv.value', 3000)->assertJsonPath('kpis.gmv.previous', 0)
            ->assertJsonCount(30, 'series')->assertJsonPath('series.0.date', '2026-09-01')->assertJsonPath('series.4.gmv', 1000);

        // Mois futur ou invalide : mois en cours.
        $this->withToken($t)->getJson('/api/v1/admin/overview?month=2027-01')->assertJsonPath('period.month', '2026-10');
        $this->withToken($t)->getJson('/api/v1/admin/overview?month=n-importe')->assertJsonPath('period.month', '2026-10');
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
