<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Services\WaitlistService;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_is_notified_once_when_a_seat_frees_up(): void
    {
        $this->seed(ServiceSeeder::class);
        $member = User::factory()->create();
        $this->actingAs($member)->postJson('/api/v1/services/netflix/waitlist')->assertOk()->assertJsonPath('waiting', true);
        $this->getJson('/api/v1/bootstrap')->assertJsonPath('waitlist.0', 'netflix');

        // Aucune place : personne n'est prévenu.
        $waitlist = app(WaitlistService::class);
        $this->assertSame(0, $waitlist->notifyAvailable());

        // Une offre avec des places passe en ligne.
        User::factory()->create()->hostOffers()->create([
            'service_id' => Service::where('slug', 'netflix')->value('id'), 'plan' => 'premium', 'plan_label' => 'Premium · 4 écrans',
            'devices' => ['tv'], 'seats' => 2, 'price' => 2400, 'access_mode' => 'credentials', 'status' => 'live', 'approved_at' => now(),
        ]);
        $this->assertSame(1, $waitlist->notifyAvailable());
        $this->assertSame(0, $waitlist->notifyAvailable(), 'une seule fois');
        $this->assertSame(1, $member->notifications()->where('data->title', 'like', 'Une place%')->count());
        $this->getJson('/api/v1/bootstrap')->assertJsonCount(0, 'waitlist');

        // Se réinscrire / se retirer.
        $this->postJson('/api/v1/services/netflix/waitlist')->assertOk();
        $this->deleteJson('/api/v1/services/netflix/waitlist')->assertOk()->assertJsonPath('waiting', false);
        $this->getJson('/api/v1/bootstrap')->assertJsonCount(0, 'waitlist');
    }
}
