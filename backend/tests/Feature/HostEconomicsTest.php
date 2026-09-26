<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Règles économiques de la bêta : prix minimum par place, retraits (minimum + frais à la charge de l'hôte). */
class HostEconomicsTest extends TestCase
{
    use RefreshDatabase;

    public function test_withdrawal_needs_minimum_and_host_pays_sending_fee(): void
    {
        config(['services.payments.withdrawal_min' => 2000, 'services.payments.withdrawal_fee_fixed' => 0, 'services.payments.withdrawal_fee_percent' => 1]);
        $host = User::factory()->create(['balance' => 5000]);

        $this->actingAs($host)->postJson('/api/v1/host/withdrawals', ['amount' => 1500])->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'Retrait possible à partir de 2 000 FCFA.');
        $this->getJson('/api/v1/host')->assertJsonPath('data.withdrawal.min', 2000);

        $this->postJson('/api/v1/host/withdrawals', ['amount' => 5000])->assertOk();
        $withdrawal = $host->payments()->where('type', 'withdrawal')->sole();
        $this->assertSame(4950, $withdrawal->amount);
        $this->assertSame(50, $withdrawal->service_fee);
        $this->assertSame(0, $host->fresh()->balance);
    }

    public function test_price_per_seat_is_at_least_1000(): void
    {
        $this->seed(ServiceSeeder::class);
        $host = User::factory()->create();
        $offer = $host->hostOffers()->create([
            'service_id' => Service::where('slug', 'netflix')->value('id'), 'plan' => 'premium', 'plan_label' => 'Premium · 4 écrans',
            'devices' => ['tv'], 'seats' => 2, 'price' => 2500, 'access_mode' => 'credentials', 'access_email' => 'h@x.ci', 'access_password' => 'p', 'status' => 'live', 'approved_at' => now(),
        ]);

        $this->actingAs($host)->patchJson("/api/v1/host/offers/{$offer->id}", ['price' => 900])->assertStatus(422);
        $this->patchJson("/api/v1/host/offers/{$offer->id}", ['price' => 1000])->assertOk();
    }
}
