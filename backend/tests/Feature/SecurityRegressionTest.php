<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\HostOffer;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ReferralService;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Failles trouvées à l'audit de sécurité : chacune reste fermée. */
class SecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServiceSeeder::class);
        $this->host = User::factory()->create(['first_name' => 'Koffi', 'last_name' => 'Yao']);
    }

    private function offer(): HostOffer
    {
        return $this->host->hostOffers()->create([
            'service_id' => Service::where('slug', 'netflix')->value('id'), 'plan' => 'premium', 'plan_label' => 'Premium · 4 écrans',
            'devices' => ['tv'], 'seats' => 3, 'price' => 2400, 'access_mode' => 'credentials',
            'access_email' => 'koffi@mail.ci', 'access_password' => 'ancien-mdp', 'status' => 'live', 'approved_at' => now(),
        ]);
    }

    public function test_expired_members_never_receive_new_credentials(): void
    {
        $offer = $this->offer();
        $former = User::factory()->create();
        $sub = $former->subscriptions()->create([
            'service_id' => $offer->service_id, 'host_offer_id' => $offer->id, 'status' => 'expired',
            'starts_at' => now()->subMonths(2), 'ends_at' => now()->subMonth(), 'pay_method' => 'wave',
            'access_email' => 'koffi@mail.ci', 'access_password' => 'ancien-mdp',
        ]);

        $this->actingAs($this->host)->patchJson("/api/v1/host/offers/{$offer->id}", ['password' => 'nouveau-mdp-secret'])->assertOk();

        $this->assertSame('ancien-mdp', $sub->fresh()->access_password);
        $this->assertSame(0, $former->notifications()->count());
        // Et le coffre d'un abonnement terminé n'affiche plus rien.
        $this->actingAs($former)->getJson('/api/v1/subscriptions')->assertJsonPath('data.0.password', null)->assertJsonPath('data.0.email', null);
    }

    public function test_credit_cannot_be_doubled_with_expire_resend_cancel(): void
    {
        config(['services.payments.fake_delay' => 9999]);
        $offer = $this->offer();
        $member = tap(User::factory()->create(), fn (User $u) => $u->forceFill(['referral_credit' => 1000])->save());
        $pay = fn () => $this->actingAs($member)->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $offer->id, 'months' => 1, 'method' => 'wave', 'phone' => '0758421121'])
            ->assertCreated()->json('data.ref');

        $p1 = $pay();
        $this->assertSame(0, $member->fresh()->referral_credit);
        // P1 expire : le crédit revient.
        $one = Payment::where('reference', $p1)->first();
        $one->update(['status' => PaymentStatus::Expired]);
        app(ReferralService::class)->restoreCredit($one);
        $this->assertSame(1000, $member->fresh()->referral_credit);

        // Le crédit sert à P2 ; P1 ne peut plus être relancé avec la remise.
        $p2 = $pay();
        $this->postJson("/api/v1/payments/{$p1}/resend")->assertStatus(422);
        $this->postJson("/api/v1/payments/{$p2}/cancel")->assertOk();
        $this->postJson("/api/v1/payments/{$p1}/cancel")->assertOk();
        $this->assertSame(1000, $member->fresh()->referral_credit);
    }

    public function test_late_confirmation_after_credit_was_spent_never_gives_it_back_twice(): void
    {
        config(['services.payments.fake_delay' => 9999]);
        $offer = $this->offer();
        $member = tap(User::factory()->create(), fn (User $u) => $u->forceFill(['referral_credit' => 1000])->save());
        $p1 = $this->actingAs($member)->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $offer->id, 'months' => 1, 'method' => 'wave', 'phone' => '0758421121'])->json('data.ref');
        $one = Payment::where('reference', $p1)->first();
        $this->postJson("/api/v1/payments/{$p1}/cancel")->assertOk();
        $this->assertSame(1000, $member->fresh()->referral_credit);
        // Le crédit est dépensé ailleurs…
        $member->forceFill(['referral_credit' => 0])->save();

        // …puis la passerelle confirme P1 quand même : rien à reprendre, et rien ne sera rendu deux fois.
        app(PaymentService::class)->confirm($one);
        $this->assertSame(1000, $one->fresh()->credit_shortfall);
        $one->update(['refunded_at' => now()]);
        app(ReferralService::class)->restoreCredit($one->fresh());
        $this->assertSame(0, $member->fresh()->referral_credit);
    }

    public function test_credit_funded_share_comes_back_as_credit_not_cash(): void
    {
        config(['services.payments.fake_delay' => 0, 'services.payments.poll_interval' => 0]);
        $offer = $this->offer();
        $member = tap(User::factory()->create(), fn (User $u) => $u->forceFill(['referral_credit' => 2000])->save());
        $ref = $this->actingAs($member)->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $offer->id, 'months' => 1, 'method' => 'wave', 'phone' => '0758421121'])->json('data.ref');
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'succeeded');
        $payment = Payment::where('reference', $ref)->first();
        $this->assertSame(2000, $payment->credit_used);

        $request = $payment->joinRequest;
        $this->actingAs($this->host)->postJson("/api/v1/host/requests/{$request->id}/accept")->assertOk();
        $memberRow = $offer->members()->where('user_id', $member->id)->first();
        $this->actingAs($this->host)->deleteJson("/api/v1/host/offers/{$offer->id}/members/{$memberRow->id}")->assertOk();

        // 2 400 de prix : 2 000 payés en crédit reviennent en crédit, seuls 400 partent en argent.
        $this->assertSame(2000, $member->fresh()->referral_credit);
        $this->assertSame(400, (int) $member->payments()->where('type', 'refund')->sum('amount'));
    }

    public function test_resend_and_cancel_only_apply_to_subscription_payments(): void
    {
        $member = tap(User::factory()->create(), fn (User $u) => $u->forceFill(['balance' => 5000])->save());
        $w = $member->payments()->create(['type' => 'withdrawal', 'status' => 'pending', 'label' => 'Retrait', 'amount' => 1000, 'method' => 'wave', 'phone' => '0700000000']);
        $this->actingAs($member)->postJson("/api/v1/payments/{$w->reference}/cancel")->assertNotFound();
        $this->postJson("/api/v1/payments/{$w->reference}/resend")->assertNotFound();
        $this->assertSame(PaymentStatus::Pending, $w->fresh()->status);
    }
}
