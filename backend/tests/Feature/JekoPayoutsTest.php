<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PayMethod;
use App\Models\Admin;
use App\Models\Payment;
use App\Models\User;
use App\Services\JekoPayouts;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JekoPayoutsTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.payments.driver' => 'jeko', 'services.payments.manual_payouts' => true,
            'services.jeko.key' => 'k', 'services.jeko.key_id' => 'i', 'services.jeko.store_id' => 's',
            'services.jeko.auto_payout_methods' => ['wave'],
            'services.payments.withdrawal_min' => 500, 'services.payments.withdrawal_fee_percent' => 1, 'services.payments.withdrawal_fee_fixed' => 0,
        ]);
        $this->host = User::factory()->create(['phone' => '0701020304', 'balance' => 5000]);
    }

    private function withdrawal(PayMethod $method = PayMethod::Wave, string $phone = '0701020304'): Payment
    {
        return $this->host->payments()->create([
            'type' => PaymentType::Withdrawal, 'status' => PaymentStatus::Pending, 'label' => 'Retrait des gains',
            'amount' => 1980, 'service_fee' => 20, 'method' => $method, 'phone' => $phone,
        ]);
    }

    public function test_wave_withdrawal_is_sent_by_jeko_and_marked_paid(): void
    {
        Http::fake([
            '*/partner_api/contacts' => fn (Request $r) => $r->method() === 'GET' ? Http::response([]) : Http::response(['id' => 'c1', 'name' => 'x', 'paymentMethod' => 'wave', 'identifier' => ['number' => '+2250701020304']]),
            '*/partner_api/transfers' => Http::response(['id' => 'tr1', 'status' => 'success', 'amount' => ['amount' => 198000], 'fees' => ['amount' => 3000]]),
        ]);
        $p = $this->withdrawal();

        $this->assertSame(1, app(JekoPayouts::class)->run());
        $p->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $p->status);
        $this->assertSame('tr1', $p->provider_transaction_id);
        $this->assertSame(30, $p->provider_fee);
        $this->assertSame('Retrait envoyé', $this->host->notifications()->latest()->first()->data['title']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/partner_api/contacts') && $r->method() === 'POST' && $r['identifier']['number'] === '+2250701020304' && $r['paymentMethod'] === 'wave');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/partner_api/transfers') && $r['amountCents'] === 198000 && $r['reference'] === $p->reference && $r['contactId'] === 'c1');
    }

    public function test_pending_transfer_is_followed_until_success_and_never_sent_twice(): void
    {
        Http::fake([
            '*/partner_api/contacts' => Http::response([['id' => 'c1', 'paymentMethod' => 'wave', 'identifier' => ['number' => '+2250701020304']]]),
            '*/partner_api/transfers/tr1' => Http::sequence()->push(['id' => 'tr1', 'status' => 'pending'])->push(['id' => 'tr1', 'status' => 'success', 'fees' => ['amount' => 3000]]),
            '*/partner_api/transfers' => Http::response(['id' => 'tr1', 'status' => 'pending']),
        ]);
        $p = $this->withdrawal();
        $payouts = app(JekoPayouts::class);

        $payouts->run();
        $this->assertSame(PaymentStatus::Pending, $p->fresh()->status);
        $payouts->run();
        $this->assertSame(PaymentStatus::Pending, $p->fresh()->status);
        $payouts->run();
        $this->assertSame(PaymentStatus::Succeeded, $p->fresh()->status);
        // Un seul transfert créé.
        Http::assertSentCount(1 + 1 + 2); // contacts, transfers, 2 relectures
    }

    public function test_refusal_stops_the_auto_payout_and_keeps_it_for_the_team(): void
    {
        Http::fake([
            '*/partner_api/contacts' => Http::response([['id' => 'c1', 'paymentMethod' => 'wave', 'identifier' => ['number' => '+2250701020304']]]),
            '*/partner_api/transfers' => Http::response(['code' => 'insufficient_balance', 'message' => 'Insufficient balance'], 400),
        ]);
        $p = $this->withdrawal();
        app(JekoPayouts::class)->run();
        app(JekoPayouts::class)->run();

        $p->refresh();
        $this->assertSame(PaymentStatus::Pending, $p->status);
        $this->assertSame('Solde Jèko insuffisant.', $p->payout_error);
        Http::assertSentCount(2); // une seule tentative de transfert
    }

    public function test_network_cut_during_the_transfer_is_never_retried_automatically(): void
    {
        $attempts = 0;
        Http::fake([
            '*/partner_api/contacts' => Http::response([['id' => 'c1', 'paymentMethod' => 'wave', 'identifier' => ['number' => '+2250701020304']]]),
            '*/partner_api/transfers' => function () use (&$attempts) {
                $attempts++;
                throw new ConnectionException('timeout');
            },
        ]);
        $p = $this->withdrawal();
        app(JekoPayouts::class)->run();
        app(JekoPayouts::class)->run();

        $this->assertStringContainsString('Vérifie dans Jèko', $p->fresh()->payout_error);
        $this->assertSame(1, $attempts);
    }

    public function test_methods_not_enabled_stay_manual(): void
    {
        Http::fake();
        $this->withdrawal(PayMethod::OrangeMoney, '0701020304');
        $this->assertSame(0, app(JekoPayouts::class)->run());
        Http::assertNothingSent();
    }

    public function test_withdrawal_request_tells_the_host_it_arrives_in_minutes(): void
    {
        $this->actingAs($this->host)->getJson('/api/v1/host')->assertJsonPath('data.withdrawal.auto', true);
        $this->actingAs($this->host)->postJson('/api/v1/host/withdrawals', ['amount' => 2000])->assertSuccessful();
        $this->assertSame('Retrait en cours', $this->host->notifications()->latest()->first()->data['title']);
    }

    public function test_admin_can_retry_a_refused_payout(): void
    {
        $p = $this->withdrawal();
        $p->update(['payout_attempted_at' => now(), 'payout_error' => 'Solde Jèko insuffisant.']);
        Http::fake([
            '*/partner_api/contacts' => Http::response([['id' => 'c1', 'paymentMethod' => 'wave', 'identifier' => ['number' => '+2250701020304']]]),
            '*/partner_api/transfers' => Http::response(['id' => 'tr9', 'status' => 'success', 'fees' => ['amount' => 3000]]),
        ]);
        Admin::create(['name' => 'Axel', 'email' => 'admin@sub.ci', 'password' => 'un-mot-de-passe-long']);
        $this->withToken($this->postJson('/api/v1/admin/auth/login', ['email' => 'admin@sub.ci', 'password' => 'un-mot-de-passe-long'])->json('token'));

        $this->postJson("/api/v1/admin/payments/{$p->id}/auto-send")->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->postJson("/api/v1/admin/payments/{$p->id}/auto-send")->assertStatus(409);
    }
}
