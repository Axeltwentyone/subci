<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\HostOffer;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Payments\JekoGateway;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JekoTest extends TestCase
{
    use RefreshDatabase;

    private HostOffer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServiceSeeder::class);
        config(['services.jeko' => [
            'base_url' => 'https://jeko.test', 'key' => 'jk_key', 'key_id' => 'jk_id', 'store_id' => 'store_1', 'webhook_secret' => 'jk_whsec',
        ], 'services.payments.request_ttl' => 1800]);
        $this->app->instance(PaymentGateway::class, new JekoGateway(config('services.jeko')));
        $this->offer = User::factory()->create()->hostOffers()->create([
            'service_id' => Service::where('slug', 'netflix')->value('id'), 'plan' => 'premium', 'plan_label' => 'Premium · 4 écrans',
            'devices' => ['tv'], 'seats' => 3, 'price' => 2500, 'access_mode' => 'credentials', 'access_email' => 'h@x.ci', 'access_password' => 'p', 'status' => 'live', 'approved_at' => now(),
        ]);
    }

    private function checkout(string $method = 'wave')
    {
        $this->actingAs(User::factory()->create());

        return $this->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $this->offer->id, 'months' => 1, 'method' => $method, 'phone' => '0758421121']);
    }

    private function paid(int $cents = 250000, string $status = 'success'): array
    {
        return ['id' => 'pr_1', 'status' => $status, 'transaction' => ['id' => 'txn_1', 'amount' => ['amount' => $cents, 'currency' => 'XOF'], 'fees' => ['amount' => 2500, 'currency' => 'XOF']]];
    }

    public function test_checkout_creates_jeko_payment_request_and_returns_redirect_url(): void
    {
        Http::fake(['jeko.test/*' => Http::response(['id' => 'pr_1', 'status' => 'pending', 'redirectUrl' => 'https://pay.jeko.africa/pr_1'])]);

        $data = $this->checkout('om')->assertCreated()->json('data');
        $this->assertSame('https://pay.jeko.africa/pr_1', $data['checkoutUrl']);
        Http::assertSent(fn ($r) => $r->url() === 'https://jeko.test/partner_api/payment_requests'
            && $r->hasHeader('X-API-KEY', 'jk_key') && $r->hasHeader('X-API-KEY-ID', 'jk_id')
            && $r['storeId'] === 'store_1' && $r['amountCents'] === 250000 && $r['currency'] === 'XOF'
            && str_starts_with($r['reference'], $data['ref'].'_')
            && $r['paymentDetails']['type'] === 'redirect' && $r['paymentDetails']['data']['paymentMethod'] === 'orange'
            && str_ends_with($r['paymentDetails']['data']['successUrl'], '/retour/'.$data['ref']));
        $this->assertSame('pr_1', Payment::sole()->provider_reference);
    }

    public function test_card_is_refused_with_a_clear_message(): void
    {
        Http::fake();
        $this->checkout('card')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_poll_confirms_only_on_success_with_the_right_amount(): void
    {
        Http::fakeSequence('jeko.test/*')
            ->push(['id' => 'pr_1', 'status' => 'pending', 'redirectUrl' => 'https://x'])
            ->push(['id' => 'pr_1', 'status' => 'pending'])
            ->push($this->paid());

        $ref = $this->checkout()->json('data.ref');
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'pending');
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'succeeded');
    }

    public function test_wrong_amount_is_never_confirmed(): void
    {
        Http::fakeSequence('jeko.test/*')
            ->push(['id' => 'pr_1', 'status' => 'pending', 'redirectUrl' => 'https://x'])
            ->push($this->paid(20000));

        $ref = $this->checkout()->json('data.ref');
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'failed');
    }

    public function test_signed_webhook_triggers_verification_and_bad_signature_is_rejected(): void
    {
        Http::fakeSequence('jeko.test/*')
            ->push(['id' => 'pr_1', 'status' => 'pending', 'redirectUrl' => 'https://x'])
            ->push($this->paid());
        $ref = $this->checkout()->json('data.ref');
        $this->app['auth']->forgetGuards();

        $body = json_encode(['id' => 'txn_1', 'status' => 'success', 'transactionType' => 'payment',
            'transactionDetails' => ['id' => 'pr_1', 'reference' => $ref.'_abc123']]);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_JEKO_EVENT' => 'TRANSACTION_COMPLETED'];

        $this->call('POST', '/api/v1/webhooks/jeko', [], [], [], $server + ['HTTP_JEKO_SIGNATURE' => 'faux'], $body)->assertStatus(401);
        $this->assertSame('pending', Payment::sole()->status->value);

        $sig = hash_hmac('sha256', $body, 'jk_whsec');
        $this->call('POST', '/api/v1/webhooks/jeko', [], [], [], $server + ['HTTP_JEKO_SIGNATURE' => $sig], $body)->assertOk();
        $this->assertSame('succeeded', Payment::sole()->status->value);
    }
}
