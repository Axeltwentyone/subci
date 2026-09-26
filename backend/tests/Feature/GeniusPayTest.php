<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\Admin;
use App\Models\HostOffer;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Payments\GeniusPayGateway;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeniusPayTest extends TestCase
{
    use RefreshDatabase;

    private HostOffer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServiceSeeder::class);
        $this->app->instance(PaymentGateway::class, new GeniusPayGateway(config('services.geniuspay')));
        config(['services.payments.request_ttl' => 1800]);
        $this->offer = User::factory()->create()->hostOffers()->create([
            'service_id' => Service::where('slug', 'netflix')->value('id'), 'plan' => 'premium', 'plan_label' => 'Premium · 4 écrans',
            'devices' => ['tv'], 'seats' => 3, 'price' => 2500, 'access_mode' => 'credentials', 'access_email' => 'h@x.ci', 'access_password' => 'p', 'status' => 'live', 'approved_at' => now(),
        ]);
    }

    private function checkout(string $method = 'wave'): array
    {
        $this->actingAs(User::factory()->create(['first_name' => 'Aya', 'last_name' => 'Koné', 'name' => 'Aya Koné']));

        return $this->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $this->offer->id, 'months' => 1, 'method' => $method, 'phone' => '0758421121'])
            ->assertCreated()->json('data');
    }

    public function test_checkout_creates_geniuspay_payment_and_returns_checkout_url(): void
    {
        Http::fake(['geniuspay.test/*' => Http::response(['success' => true, 'data' => ['reference' => 'GP_REF_1', 'checkout_url' => 'https://geniuspay.ci/checkout/GP_REF_1']])]);

        $data = $this->checkout('om');
        $this->assertSame('https://geniuspay.ci/checkout/GP_REF_1', $data['checkoutUrl']);

        Http::assertSent(fn ($r) => $r->url() === 'https://geniuspay.test/api/v1/merchant/payments'
            && $r->hasHeader('X-API-Key', 'pk_test') && $r['amount'] === 2500 && $r['payment_method'] === 'orange_money'
            && $r['customer']['phone'] === '+2250758421121' && str_ends_with($r['success_url'], '/pay/'.$data['ref']));
        $this->assertSame('GP_REF_1', Payment::sole()->provider_reference);
    }

    public function test_poll_confirms_only_when_geniuspay_says_completed_with_right_amount(): void
    {
        Http::fakeSequence('geniuspay.test/*')
            ->push(['success' => true, 'data' => ['reference' => 'GP_REF_2', 'checkout_url' => 'https://x']])
            ->push(['success' => true, 'data' => ['status' => 'pending', 'amount' => 2500]])
            ->push(['success' => true, 'data' => ['status' => 'completed', 'amount' => 2500]]);

        $ref = $this->checkout()['ref'];
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'pending');
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.joinStatus', 'pending');
    }

    public function test_wrong_amount_is_never_confirmed(): void
    {
        Http::fakeSequence('geniuspay.test/*')
            ->push(['success' => true, 'data' => ['reference' => 'GP_REF_3', 'checkout_url' => 'https://x']])
            ->push(['success' => true, 'data' => ['status' => 'completed', 'amount' => 200]]);

        $ref = $this->checkout()['ref'];
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'failed');
    }

    public function test_gateway_error_returns_friendly_502(): void
    {
        Http::fake(['geniuspay.test/*' => Http::response(['success' => false, 'message' => 'Invalid key'], 401)]);
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $this->offer->id, 'months' => 1, 'method' => 'wave', 'phone' => '0758421121'])
            ->assertStatus(502)->assertJsonPath('message', 'Le paiement n’a pas pu démarrer. Réessaie dans un instant.');
    }

    public function test_signed_webhook_triggers_verification_and_bad_signature_is_rejected(): void
    {
        Http::fakeSequence('geniuspay.test/*')
            ->push(['success' => true, 'data' => ['reference' => 'GP_REF_4', 'checkout_url' => 'https://x']])
            ->push(['success' => true, 'data' => ['status' => 'completed', 'amount' => 2500]]);
        $this->checkout();
        $this->app['auth']->forgetGuards();

        $body = json_encode(['event' => 'payment.success', 'data' => ['reference' => 'GP_REF_4', 'status' => 'completed']]);
        $ts = (string) time();
        $headers = ['X-Webhook-Timestamp' => $ts, 'X-Webhook-Event' => 'payment.success', 'Content-Type' => 'application/json', 'Accept' => 'application/json'];

        $this->call('POST', '/api/v1/webhooks/geniuspay', [], [], [], $this->serverHeaders($headers + ['X-Webhook-Signature' => 'faux']), $body)->assertStatus(401);
        $this->assertSame('pending', Payment::sole()->status->value);

        $sig = hash_hmac('sha256', $ts.'.'.$body, 'whsec_test');
        $this->call('POST', '/api/v1/webhooks/geniuspay', [], [], [], $this->serverHeaders($headers + ['X-Webhook-Signature' => $sig]), $body)->assertOk();
        $this->assertSame('succeeded', Payment::sole()->status->value);
    }

    public function test_refund_and_withdrawal_are_manual_with_geniuspay(): void
    {
        config(['services.payments.manual_payouts' => true]);
        $host = $this->offer->user;
        $host->forceFill(['balance' => 5000])->save();
        $this->actingAs($host)->postJson('/api/v1/host/withdrawals', ['amount' => 2000])->assertOk();

        $w = Payment::where('type', 'withdrawal')->sole();
        $this->assertSame('pending', $w->status->value);
        $this->artisan('payouts:done', ['reference' => $w->reference])->assertSuccessful();
        $this->assertSame('succeeded', $w->fresh()->status->value);
        $this->assertEqualsCanonicalizing(['Retrait demandé', 'Retrait envoyé'], $host->notifications()->pluck('data')->pluck('title')->all());
    }

    private function serverHeaders(array $headers): array
    {
        return collect($headers)->mapWithKeys(fn ($v, $k) => [in_array($k, ['Content-Type']) ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all();
    }

    public function test_return_url_follows_app_origin_only_if_allowed(): void
    {
        config(['app.frontend_origins' => ['http://localhost:5173', 'http://localhost:4173'], 'app.frontend_url' => 'http://localhost:5173']);
        // Chaque demande a sa propre référence chez GeniusPay.
        Http::fakeSequence('geniuspay.test/*')
            ->push(['success' => true, 'data' => ['reference' => 'GP_R1', 'checkout_url' => 'https://x']])
            ->push(['success' => true, 'data' => ['reference' => 'GP_R2', 'checkout_url' => 'https://x']]);
        $this->actingAs(User::factory()->create());
        $body = ['serviceId' => 'netflix', 'offerId' => $this->offer->id, 'months' => 1, 'method' => 'wave', 'phone' => '0758421121'];

        $ref = $this->postJson('/api/v1/payments', $body + ['returnOrigin' => 'http://localhost:4173'])->assertCreated()->json('data.ref');
        Http::assertSent(fn ($r) => $r['success_url'] === "http://localhost:4173/pay/{$ref}");

        $this->actingAs(User::factory()->create());
        $ref = $this->postJson('/api/v1/payments', $body + ['returnOrigin' => 'https://phishing.example'])->assertCreated()->json('data.ref');
        Http::assertSent(fn ($r) => $r['success_url'] === "http://localhost:5173/pay/{$ref}");
    }

    public function test_late_return_after_link_expiry_still_confirms_if_paid(): void
    {
        Http::fakeSequence('geniuspay.test/*')
            ->push(['success' => true, 'data' => ['reference' => 'GP_LATE', 'checkout_url' => 'https://x']])
            ->push(['success' => true, 'data' => ['status' => 'completed', 'amount' => 2500]]);
        $ref = $this->checkout()['ref'];

        $this->travel(2)->hours();
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'succeeded');
    }

    public function test_reconcile_confirms_payments_whose_member_never_came_back(): void
    {
        Http::fakeSequence('geniuspay.test/*')
            ->push(['success' => true, 'data' => ['reference' => 'GP_GONE', 'checkout_url' => 'https://x']])
            ->push(['success' => true, 'data' => ['status' => 'completed', 'amount' => 2500]]);
        $this->checkout();

        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame('succeeded', Payment::sole()->status->value);
    }

    public function test_fees_added_on_top_by_geniuspay_still_confirm(): void
    {
        Http::fakeSequence('geniuspay.test/*')
            ->push(['success' => true, 'data' => ['reference' => 'GP_FEES', 'checkout_url' => 'https://x']])
            ->push(['success' => true, 'data' => ['status' => 'completed', 'amount' => '2575.00', 'fees' => 75, 'provider' => 'wave']]);

        $ref = $this->checkout('om')['ref'];
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'succeeded');
    }

    public function test_admin_reread_recovers_a_payment_wrongly_marked_failed(): void
    {
        Http::fakeSequence('geniuspay.test/*')
            ->push(['success' => true, 'data' => ['reference' => 'GP_LATE', 'checkout_url' => 'https://x']])
            ->push(['success' => true, 'data' => ['status' => 'failed', 'amount' => 2500]])
            ->push(['success' => true, 'data' => ['status' => 'completed', 'amount' => 2500]]);

        $ref = $this->checkout()['ref'];
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'failed');

        $this->app['auth']->forgetGuards();
        config(['services.admin.require_2fa' => false]);
        Admin::create(['name' => 'Test', 'email' => 'relire@sub.ci', 'password' => 'un-mot-de-passe-long']);
        $token = $this->postJson('/api/v1/admin/auth/login', ['email' => 'relire@sub.ci', 'password' => 'un-mot-de-passe-long'])->json('token');
        $this->withToken($token)->postJson('/api/v1/admin/payments/'.Payment::sole()->id.'/reconcile')->assertOk()
            ->assertJsonPath('data.status', 'succeeded');
    }
}
