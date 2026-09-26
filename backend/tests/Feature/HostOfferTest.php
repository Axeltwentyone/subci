<?php

namespace Tests\Feature;

use App\Models\HostOffer;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostOfferTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private HostOffer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServiceSeeder::class);
        $this->host = User::factory()->create();
        $netflix = Service::where('slug', 'netflix')->first();
        $this->offer = $this->host->hostOffers()->create([
            'service_id' => $netflix->id, 'plan' => 'premium', 'plan_label' => 'Premium · 4 écrans', 'devices' => ['phone', 'tv'], 'seats' => 3, 'price' => 2500,
            'access_mode' => 'credentials', 'access_email' => 'old@mail.ci', 'access_password' => 'old-pass', 'status' => 'live', 'approved_at' => now(),
        ]);
        $this->offer->members()->create(['name' => 'Koffi', 'color' => '#FFB38F']);
        $this->offer->members()->create(['name' => 'Mariam', 'color' => '#9FD7BE']);
        $this->actingAs($this->host);
    }

    /** Places Netflix visibles par un autre utilisateur. */
    private function freeSeats(): int
    {
        $this->app['auth']->forgetGuards();

        return collect($this->getJson('/api/v1/services')->json('data'))->firstWhere('id', 'netflix')['free'];
    }

    public function test_host_can_change_price_and_seats_within_limits(): void
    {
        $url = "/api/v1/host/offers/{$this->offer->id}";

        $this->patchJson($url, ['seats' => 1])->assertStatus(422)
            ->assertJsonPath('errors.seats.0', 'Tu as 2 membres ou demandes : impossible de descendre en dessous.');
        // Netflix Premium : 5 profils → 5 places au plus.
        $this->patchJson($url, ['seats' => 6])->assertStatus(422);

        $this->patchJson($url, ['price' => 2200, 'seats' => 2])->assertOk()
            ->assertJsonPath('data.price', 2200)->assertJsonPath('data.seats', 2);
        $this->assertSame(0, $this->freeSeats());

        $this->actingAs($this->host)->patchJson($url, ['seats' => 3])->assertOk();
        $this->assertSame(1, $this->freeSeats());
    }

    public function test_new_credentials_reach_linked_members(): void
    {
        $member = User::factory()->create();
        $sub = $member->subscriptions()->create([
            'service_id' => $this->offer->service_id, 'host_offer_id' => $this->offer->id, 'status' => 'active',
            'starts_at' => now(), 'ends_at' => now()->addMonth(), 'pay_method' => 'om', 'access_password' => 'old-pass',
        ]);

        $this->patchJson("/api/v1/host/offers/{$this->offer->id}", ['password' => 'new-pass-42'])->assertOk();

        $this->assertSame('new-pass-42', $sub->fresh()->access_password);
        $this->assertSame('Nouveaux accès Netflix', $member->notifications()->first()->data['title']);
    }

    public function test_remove_member_frees_a_seat(): void
    {
        $member = $this->offer->members()->first();
        $this->deleteJson("/api/v1/host/offers/{$this->offer->id}/members/{$member->id}")->assertOk()
            ->assertJsonCount(1, 'data.members');
        $this->assertSame(2, $this->freeSeats());
    }

    public function test_pause_resume_and_close_update_catalog(): void
    {
        $base = "/api/v1/host/offers/{$this->offer->id}";
        $this->postJson("{$base}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
        $this->assertSame(0, $this->freeSeats());
        $this->actingAs($this->host)->postJson("{$base}/resume")->assertOk()->assertJsonPath('data.status', 'live');
        $this->assertSame(1, $this->freeSeats());
        $this->actingAs($this->host)->postJson("{$base}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertSame(0, $this->freeSeats());
        $this->actingAs($this->host)->patchJson($base, ['price' => 2000])->assertStatus(409);
    }

    public function test_offer_in_review_is_hidden_until_approved(): void
    {
        config(['services.offers.auto_approve_after' => null]);
        $this->offer->update(['status' => 'review']);
        $this->assertSame(0, $this->freeSeats());

        $this->actingAs($this->host)->postJson("/api/v1/host/offers/{$this->offer->id}/pause")->assertStatus(409);
        $this->postJson("/api/v1/host/offers/{$this->offer->id}/resume")->assertStatus(409);

        $this->artisan('offers:approve', ['offer' => $this->offer->id])->assertSuccessful();
        $this->assertSame(1, $this->freeSeats());
        $this->assertSame('Ton offre est en ligne', $this->host->notifications()->first()->data['title']);
    }

    public function test_other_users_cannot_edit_offer(): void
    {
        $this->actingAs(User::factory()->create());
        $this->patchJson("/api/v1/host/offers/{$this->offer->id}", ['price' => 1000])->assertNotFound();
    }
}
