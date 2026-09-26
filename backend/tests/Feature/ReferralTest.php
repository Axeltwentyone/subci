<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\HostOffer;
use App\Models\JoinRequest;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\Service;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private HostOffer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServiceSeeder::class);
        config(['services.payments.fake_delay' => 0, 'services.payments.service_fee' => 200, 'services.payments.service_fee_long' => 200]);
        $this->host = User::factory()->create(['first_name' => 'Koffi', 'last_name' => 'Yao']);
        $this->offer = $this->host->hostOffers()->create([
            'service_id' => Service::where('slug', 'netflix')->value('id'), 'plan' => 'premium', 'plan_label' => 'Premium · 4 écrans',
            'devices' => ['tv'], 'seats' => 5, 'price' => 2400, 'access_mode' => 'credentials',
            'access_email' => 'k@mail.ci', 'access_password' => 'secret', 'status' => 'live', 'approved_at' => now(),
        ]);
    }

    private function checkout(User $user): Payment
    {
        $ref = $this->actingAs($user)->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $this->offer->id, 'months' => 1, 'method' => 'wave', 'phone' => '0758421121'])
            ->assertCreated()->json('data.ref');

        return Payment::where('reference', $ref)->firstOrFail();
    }

    private function payAndAccept(User $user): Payment
    {
        $payment = $this->checkout($user);
        $this->getJson("/api/v1/payments/{$payment->reference}")->assertJsonPath('data.status', 'succeeded');
        $this->actingAs($this->host)->postJson('/api/v1/host/requests/'.JoinRequest::latest('id')->value('id').'/accept')->assertOk();

        return $payment->fresh();
    }

    /** Renouvellement d'un abonnement déjà accepté (2e paiement abouti). */
    private function renew(User $user): Payment
    {
        $ref = $this->actingAs($user)->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'months' => 1, 'method' => 'wave', 'phone' => '0758421121'])
            ->assertCreated()->json('data.ref');
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'succeeded');

        return Payment::where('reference', $ref)->firstOrFail();
    }

    public function test_code_rules(): void
    {
        $alice = User::factory()->create(['first_name' => 'Alice', 'last_name' => 'K']);
        $bob = User::factory()->create();

        $this->actingAs($bob)->postJson('/api/v1/me/referral', ['code' => 'NOPE-123'])->assertStatus(422);
        $this->postJson('/api/v1/me/referral', ['code' => $bob->referral_code])->assertStatus(422);
        $this->postJson('/api/v1/me/referral', ['code' => strtolower($alice->referral_code)])->assertOk()
            ->assertJsonPath('data.referral.referredBy', 'Alice K.')->assertJsonPath('data.referral.feeWaived', false);
        $this->postJson('/api/v1/me/referral', ['code' => $alice->referral_code])->assertStatus(422);

        // Pas de parrainage croisé, ni après un premier abonnement.
        $this->actingAs($alice)->postJson('/api/v1/me/referral', ['code' => $bob->referral_code])->assertStatus(422);
        $carol = User::factory()->create();
        $this->payAndAccept($carol);
        $this->actingAs($carol)->postJson('/api/v1/me/referral', ['code' => $alice->referral_code])->assertStatus(422);
    }

    public function test_referrer_is_rewarded_only_at_referees_second_payment_and_credit_is_used_next(): void
    {
        $alice = User::factory()->create(['first_name' => 'Alice', 'last_name' => 'K']);
        $bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'D']);
        $this->actingAs($bob)->postJson('/api/v1/me/referral', ['code' => $alice->referral_code])->assertOk();

        // 1er paiement du filleul : frais normaux, pas encore de récompense.
        $payment = $this->payAndAccept($bob);
        $this->assertSame(2600, $payment->amount);
        $this->assertSame(200, $payment->service_fee);
        $this->assertSame(0, $alice->fresh()->referral_credit);
        $this->assertSame('pending', Referral::sole()->status);

        // Il renouvelle : 2e paiement abouti → 300 F de crédit pour le parrain.
        $this->renew($bob);
        $this->assertSame(300, $alice->fresh()->referral_credit);
        $this->assertSame('rewarded', Referral::sole()->status);

        // Parrain : 2 400 + 200 de frais − 300 de crédit = 2 300 payés ; l'hôte touche toujours 95 % de 2 400.
        $second = $this->payAndAccept($alice);
        $this->assertSame(2300, $second->amount);
        $this->assertSame(300, $second->credit_used);
        $this->assertSame(0, $alice->fresh()->referral_credit);
        $this->assertSame(2280, Payment::where('source_payment_id', $second->id)->value('amount'));
    }

    public function test_credit_returns_if_payment_is_abandoned_or_declined_and_is_taken_again_if_paid_late(): void
    {
        $alice = User::factory()->create();
        $alice->forceFill(['referral_credit' => 500])->save();
        config(['services.payments.fake_delay' => 3600]);

        $payment = $this->checkout($alice);
        $this->assertSame(0, $alice->fresh()->referral_credit);
        $this->postJson("/api/v1/payments/{$payment->reference}/cancel")->assertOk();
        $this->assertSame(500, $alice->fresh()->referral_credit);
        $this->postJson("/api/v1/payments/{$payment->reference}/cancel")->assertOk();
        $this->assertSame(500, $alice->fresh()->referral_credit, 'rendu une seule fois');

        // Payé quand même chez la passerelle : confirmé, et le crédit est repris.
        config(['services.payments.fake_delay' => 0]);
        $this->app->forgetInstance(PaymentGateway::class);
        app(PaymentService::class)->reconcile();
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(0, $alice->fresh()->referral_credit);

        // L'hôte refuse : remboursé de ce qu'il a payé et crédit rendu.
        $this->actingAs($this->host)->postJson('/api/v1/host/requests/'.JoinRequest::latest('id')->value('id').'/decline')->assertOk();
        $this->assertSame(500, $alice->fresh()->referral_credit);
        $this->assertSame($payment->amount, $alice->payments()->where('type', 'refund')->value('amount'));
    }

    public function test_monthly_cap_on_rewards(): void
    {
        config(['services.referral.monthly_cap' => 1]);
        $alice = User::factory()->create();
        foreach (range(1, 2) as $i) {
            $friend = User::factory()->create();
            $this->actingAs($friend)->postJson('/api/v1/me/referral', ['code' => $alice->referral_code])->assertOk();
            $this->payAndAccept($friend);
            $this->renew($friend);
        }
        $this->assertSame(300, $alice->fresh()->referral_credit);
        $this->assertSame(['capped', 'rewarded'], Referral::orderBy('status')->pluck('status')->all());
    }
}
