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
        config(['services.payments.fake_delay' => 0, 'services.payments.service_fee' => 300, 'services.payments.poll_interval' => 0]);
    }

    /** Paie sa part (Wave) et attend la confirmation. */
    private function pay(User $member, string $code): string
    {
        $ref = $this->actingAs($member)->postJson("/api/v1/circles/{$code}/pay", ['method' => 'wave', 'phone' => '0758421121'])
            ->assertCreated()->assertJsonPath('data.circleCode', $code)->json('data.ref');
        $this->getJson("/api/v1/payments/{$ref}")->assertOk()->assertJsonPath('data.status', 'succeeded');

        return $ref;
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
        $this->actingAs($moussa)->getJson("/api/v1/circles/{$code}")->assertOk()->assertJsonPath('data.isMember', false)
            ->assertJsonPath('data.mustPay', true)->assertJsonPath('data.total', 1050 + 300);
        // Rejoindre = payer sa part (l'ancien bouton gratuit est refusé).
        $this->postJson("/api/v1/circles/{$code}/join")->assertStatus(422);
        $this->pay($moussa, $code);
        $this->getJson("/api/v1/circles/{$code}")->assertJsonPath('data.isMember', true)->assertJsonPath('data.free', 2)->assertJsonPath('data.mustPay', false);
        // Déjà payée pour le mois : pas de second paiement.
        $this->postJson("/api/v1/circles/{$code}/pay", ['method' => 'wave', 'phone' => '0758421121'])->assertStatus(422);
        $this->assertSame(1, $aya->notifications()->where('data->title', 'like', '%a payé sa part%')->count());
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
        $this->pay($a, $code);

        $this->actingAs($b)->getJson('/api/v1/circles')->assertJsonCount(0, 'open');
        $this->postJson("/api/v1/circles/{$code}/pay", ['method' => 'wave', 'phone' => '0758421122'])->assertStatus(422);
    }

    public function test_owner_manages_the_circle(): void
    {
        [$aya, $moussa, $other] = User::factory()->count(3)->create();
        $code = $this->create($aya);
        $this->pay($moussa, $code);

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
        // Moussa a payé son mois : sa place lui reste jusqu'à l'échéance.
        $this->deleteJson("/api/v1/circles/{$code}/members/{$moussa->id}")->assertStatus(422);
        $this->travel(32)->days();
        $this->deleteJson("/api/v1/circles/{$code}/members/{$moussa->id}")->assertOk();
        $this->getJson("/api/v1/circles/{$code}")->assertJsonCount(1, 'data.members');

        $this->postJson("/api/v1/circles/{$code}/close")->assertOk();
        $this->actingAs($other)->getJson("/api/v1/circles/{$code}")->assertNotFound();
    }

    public function test_owner_is_paid_once_the_whole_circle_has_paid(): void
    {
        [$aya, $a, $b] = User::factory()->count(3)->create();
        $code = $this->create($aya, ['seats' => 3, 'price' => 3000]);

        $this->pay($a, $code);
        $earning = $aya->payments()->where('type', 'earning')->first();
        $this->assertSame(1000, $earning->amount); // part sans commission
        $this->assertTrue($earning->available_at->isAfter(now()->addDays(6)));
        $this->actingAs($aya)->getJson("/api/v1/circles/{$code}")->assertJsonPath('data.potReached', false);

        $this->pay($b, $code);
        $this->assertTrue($aya->payments()->where('type', 'earning')->get()->every(fn ($e) => $e->available_at->lte(now())));
        $this->actingAs($aya)->getJson("/api/v1/circles/{$code}")->assertJsonPath('data.potReached', true);

        app(\App\Services\EarningService::class)->release();
        $this->assertSame(2000, $aya->fresh()->balance);
    }

    public function test_closing_refunds_what_the_owner_has_not_received(): void
    {
        [$aya, $a] = User::factory()->count(2)->create();
        $code = $this->create($aya);
        $this->pay($a, $code);

        $this->actingAs($aya)->postJson("/api/v1/circles/{$code}/close")->assertOk()->assertJsonPath('refunded', 1);
        $refund = $a->payments()->where('type', 'refund')->first();
        $this->assertSame(1050, $refund->amount);
        $this->assertSame('pending', $refund->refund_choice);
        $this->assertSame(0, $aya->payments()->where('type', 'earning')->where('status', 'pending')->count());
    }

    public function test_reminder_then_seat_freed_when_the_share_is_not_paid(): void
    {
        [$aya, $a] = User::factory()->count(2)->create();
        $code = $this->create($aya);
        $this->pay($a, $code);
        $service = app(\App\Services\CircleService::class);

        $this->travel(28)->days();
        $this->assertSame(1, $service->sweep());
        $this->assertSame(0, $service->sweep()); // un seul rappel
        $this->actingAs($a)->getJson("/api/v1/circles/{$code}")->assertJsonPath('data.mustPay', true);

        $this->travel(7)->days();
        $service->sweep();
        $this->actingAs($aya)->getJson("/api/v1/circles/{$code}")->assertJsonCount(1, 'data.members');
    }

    public function test_paying_again_extends_the_seat_by_a_month(): void
    {
        [$aya, $a] = User::factory()->count(2)->create();
        $code = $this->create($aya);
        $this->pay($a, $code);
        $first = \Illuminate\Support\Carbon::parse($this->actingAs($a)->getJson("/api/v1/circles/{$code}")->json('data.paidUntil'));

        $this->travel(28)->days();
        $this->pay($a, $code);
        $second = \Illuminate\Support\Carbon::parse($this->actingAs($a)->getJson("/api/v1/circles/{$code}")->json('data.paidUntil'));
        $this->assertSame($first->copy()->addMonth()->toDateString(), $second->toDateString());
    }
}
