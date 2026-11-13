<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServiceSeeder::class);
    }

    private function create(User $owner, array $data = []): string
    {
        return $this->actingAs($owner)->postJson('/api/v1/circles', $data + [
            'service' => 'spotify', 'name' => 'Famille Koné', 'seats' => 4, 'price' => 4200,
        ])->assertCreated()->json('data.code');
    }

    public function test_private_circle_is_joined_with_the_invite_code_only(): void
    {
        [$aya, $moussa, $stranger] = User::factory()->count(3)->create();
        $code = $this->create($aya);

        $this->getJson('/api/v1/circles')->assertJsonPath('mine.0.name', 'Famille Koné')
            ->assertJsonPath('mine.0.share', 1050)->assertJsonPath('mine.0.free', 3)->assertJsonPath('mine.0.isOwner', true);

        // Privé : n'apparaît pas aux autres.
        $this->actingAs($stranger)->getJson('/api/v1/circles')->assertJsonCount(0, 'open');

        // Avec le lien : on voit le cercle et on le rejoint ; le créateur est prévenu.
        $this->actingAs($moussa)->getJson("/api/v1/circles/{$code}")->assertOk()->assertJsonPath('data.isMember', false);
        $this->postJson("/api/v1/circles/{$code}/join")->assertOk()->assertJsonPath('data.isMember', true)->assertJsonPath('data.free', 2);
        $this->postJson("/api/v1/circles/{$code}/join")->assertOk()->assertJsonPath('data.free', 2);
        $this->assertSame(1, $aya->notifications()->where('data->title', 'like', '%a rejoint%')->count());
    }

    public function test_open_circle_is_listed_until_full(): void
    {
        [$aya, $a, $b] = User::factory()->count(3)->create();
        // Ouvert : le créateur doit fixer le prix d'une place, au plus le prix de l'abonnement.
        $this->actingAs($aya)->postJson('/api/v1/circles', ['service' => 'spotify', 'name' => 'Ouvert', 'seats' => 2, 'price' => 4200, 'open' => true])
            ->assertJsonValidationErrors('seat_price');
        $this->postJson('/api/v1/circles', ['service' => 'spotify', 'name' => 'Ouvert', 'seats' => 2, 'price' => 4200, 'open' => true, 'seat_price' => 5000])
            ->assertJsonValidationErrors('seat_price');
        $code = $this->create($aya, ['seats' => 2, 'open' => true, 'seat_price' => 2500]);

        $this->actingAs($a)->getJson('/api/v1/circles')->assertJsonPath('open.0.code', $code)
            ->assertJsonPath('open.0.memberPrice', 2500)->assertJsonPath('open.0.share', 2100);
        // Sur la page du service concerné seulement.
        $this->getJson('/api/v1/circles?service=spotify')->assertJsonCount(1, 'open');
        $this->getJson('/api/v1/circles?service=netflix')->assertJsonCount(0, 'open');
        $this->postJson("/api/v1/circles/{$code}/join")->assertOk();

        $this->actingAs($b)->getJson('/api/v1/circles')->assertJsonCount(0, 'open');
        $this->postJson("/api/v1/circles/{$code}/join")->assertStatus(422);
    }

    public function test_owner_manages_the_circle(): void
    {
        [$aya, $moussa, $other] = User::factory()->count(3)->create();
        $code = $this->create($aya);
        $this->actingAs($moussa)->postJson("/api/v1/circles/{$code}/join")->assertOk();

        // Un membre ne gère pas le cercle ; il peut partir.
        $this->patchJson("/api/v1/circles/{$code}", ['open' => true])->assertForbidden();

        // Ouvert sans prix : la part égale sert de point de départ, puis le créateur la change.
        $this->actingAs($aya)->patchJson("/api/v1/circles/{$code}", ['open' => true, 'price' => 6000])
            ->assertOk()->assertJsonPath('data.open', true)->assertJsonPath('data.memberPrice', 1500);
        $this->patchJson("/api/v1/circles/{$code}", ['seat_price' => 1800])->assertOk()->assertJsonPath('data.memberPrice', 1800);
        $this->patchJson("/api/v1/circles/{$code}", ['seat_price' => 9000])->assertJsonValidationErrors('seat_price');
        // Redevenu privé : chacun paie la part égale.
        $this->patchJson("/api/v1/circles/{$code}", ['open' => false])->assertOk()->assertJsonPath('data.memberPrice', 1500);
        $this->patchJson("/api/v1/circles/{$code}", ['seats' => 1])->assertStatus(422);
        $this->postJson("/api/v1/circles/{$code}/leave")->assertStatus(422);
        $this->deleteJson("/api/v1/circles/{$code}/members/{$moussa->id}")->assertOk();
        $this->getJson("/api/v1/circles/{$code}")->assertJsonCount(1, 'data.members');

        $this->postJson("/api/v1/circles/{$code}/close")->assertOk();
        $this->actingAs($other)->getJson("/api/v1/circles/{$code}")->assertNotFound();
    }
}
