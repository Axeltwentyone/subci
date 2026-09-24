<?php

namespace Tests\Feature;

use App\Models\HostOffer;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServiceSeeder::class);
        config(['services.payments.fake_delay' => 0]);
        $this->host = User::factory()->create(['first_name' => 'Koffi', 'last_name' => 'Yao']);
    }

    private function offer(string $slug, array $attrs = []): HostOffer
    {
        return $this->host->hostOffers()->create($attrs + [
            'service_id' => Service::where('slug', $slug)->value('id'), 'plan_label' => 'Premium', 'seats' => 3, 'price' => 2400,
            'access_mode' => 'credentials', 'access_email' => 'koffi@mail.ci', 'access_password' => 'host-secret',
            'status' => 'live', 'approved_at' => now(),
        ]);
    }

    private function buy(string $slug, int $months = 1): string
    {
        return $this->postJson('/api/v1/payments', ['serviceId' => $slug, 'months' => $months, 'method' => 'om', 'phone' => '0758421121'])
            ->assertCreated()->json('data.ref');
    }

    public function test_catalog_only_counts_live_offers_and_hides_own_seats(): void
    {
        $this->offer('netflix');
        $this->offer('disney', ['status' => 'review', 'approved_at' => null]);

        $netflix = collect($this->getJson('/api/v1/services')->json('data'))->keyBy('id');
        $this->assertSame(3, $netflix['netflix']['free']);
        $this->assertSame(2400, $netflix['netflix']['price']);
        $this->assertSame(0, $netflix['disney']['free']);

        $this->actingAs($this->host);
        $own = collect($this->getJson('/api/v1/services')->json('data'))->keyBy('id');
        $this->assertSame(0, $own['netflix']['free']);
    }

    public function test_purchase_joins_best_offer_and_credits_host(): void
    {
        $this->offer('netflix', ['price' => 2600]);
        $cheapest = $this->offer('netflix', ['price' => 2400]);
        $member = User::factory()->create(['first_name' => 'Aya', 'last_name' => 'Koné']);
        $this->actingAs($member);

        $ref = $this->buy('netflix', 3);
        $this->getJson("/api/v1/payments/{$ref}")->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.amount', 6840);

        $sub = $member->subscriptions()->firstOrFail();
        $this->assertSame($cheapest->id, $sub->host_offer_id);
        $this->assertSame('active', $sub->status->value);
        $this->assertSame('host-secret', $sub->access_password);
        $this->assertNotSame('host-secret', DB::table('subscriptions')->value('access_password'));
        $this->assertSame('Aya K.', $cheapest->members()->first()->name);

        // 6 840 − 10 % = 6 156 pour l'hôte.
        $this->assertSame(6156, $this->host->fresh()->balance);
        $this->assertEqualsCanonicalizing(['Paiement reçu', 'Nouveau membre'], $this->host->notifications()->pluck('data')->pluck('title')->all());

        // Idempotent.
        $this->getJson("/api/v1/payments/{$ref}")->assertOk();
        $this->assertSame(1, $member->subscriptions()->count());
        $this->assertSame(6156, $this->host->fresh()->balance);
    }

    public function test_pending_payment_reserves_the_last_seat(): void
    {
        $this->offer('netflix', ['seats' => 1]);
        $this->actingAs(User::factory()->create());
        $this->buy('netflix');

        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'months' => 1, 'method' => 'om', 'phone' => '0758421121'])
            ->assertStatus(422)->assertJsonValidationErrors('service');
    }

    public function test_family_offer_activates_after_host_invite(): void
    {
        $offer = $this->offer('spotify', ['access_mode' => 'family', 'access_email' => null, 'access_password' => null, 'price' => 1500]);
        $member = User::factory()->create(['first_name' => 'Paul', 'last_name' => 'Eba']);
        $this->actingAs($member);
        $this->getJson('/api/v1/payments/'.$this->buy('spotify'))->assertJsonPath('data.status', 'succeeded');

        $sub = $member->subscriptions()->first();
        $this->assertSame('pending', $sub->status->value);
        $this->assertTrue($offer->members()->first()->invite_pending);

        $this->actingAs($this->host)->postJson("/api/v1/host/offers/{$offer->id}/invite")->assertOk()->assertJsonPath('data.pendingInvite', null);
        $this->assertSame('active', $sub->fresh()->status->value);
    }

    public function test_renewal_stays_in_group_at_hosts_current_price(): void
    {
        $offer = $this->offer('netflix');
        $member = User::factory()->create();
        $this->actingAs($member);
        $this->getJson('/api/v1/payments/'.$this->buy('netflix'));
        $end = $member->subscriptions()->first()->ends_at;

        $offer->update(['price' => 2000]);
        $ref = $this->buy('netflix');
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.amount', 2000)->assertJsonPath('data.status', 'succeeded');

        $sub = $member->subscriptions()->sole();
        $this->assertTrue($sub->ends_at->equalTo($end->copy()->addMonth()));
        $this->assertSame(1, $offer->members()->count());
    }

    public function test_no_offer_means_waitlist_and_validation_is_french(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/payments', ['serviceId' => 'youtube', 'months' => 1, 'method' => 'om', 'phone' => '0758421121'])
            ->assertStatus(422)->assertJsonPath('errors.service.0', 'Plus de place libre sur ce service. Rejoins la liste d’attente.');

        $this->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'months' => 2, 'method' => 'om', 'phone' => '07'])
            ->assertStatus(422)->assertJsonPath('errors.phone.0', 'Le numéro doit avoir 10 chiffres.')->assertJsonPath('errors.months.0', 'La durée est invalide.');
    }

    public function test_host_cannot_join_own_offer(): void
    {
        $this->offer('netflix');
        $this->actingAs($this->host);
        $this->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'months' => 1, 'method' => 'om', 'phone' => '0758421121'])->assertStatus(422);
    }

    public function test_cannot_read_someone_elses_payment(): void
    {
        $this->offer('netflix');
        $this->actingAs(User::factory()->create());
        $ref = $this->buy('netflix');

        $this->actingAs(User::factory()->create());
        $this->getJson("/api/v1/payments/{$ref}")->assertNotFound();
    }

    public function test_host_withdrawal_checks_balance(): void
    {
        $this->host->forceFill(['balance' => 10000])->save();
        $this->actingAs($this->host);

        $this->postJson('/api/v1/host/withdrawals', ['amount' => 20000])->assertStatus(422);
        $this->postJson('/api/v1/host/withdrawals', ['amount' => 6000])->assertOk()->assertJsonPath('host.balance', 4000);
    }
}
