<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Admin;
use App\Models\JekoTransaction;
use App\Models\Payment;
use App\Models\TreasuryEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TreasuryTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.jeko.fee_in_percent' => 1.5, 'services.jeko.fee_out_percent' => 1.5]);
        $admin = Admin::create(['name' => 'Axel', 'email' => 'admin@sub.ci', 'password' => 'un-mot-de-passe-long']);
        $token = $this->postJson('/api/v1/admin/auth/login', ['email' => 'admin@sub.ci', 'password' => 'un-mot-de-passe-long'])->json('token');
        $this->withToken($token);
        $this->member = User::factory()->create(['phone' => '0700000001']);
        $this->host = User::factory()->create(['phone' => '0700000002']);
    }

    /** Membre paie 2 800 (dont 300 de frais), l'hôte gagne 2 375 sur 2 500 (125 de commission). */
    private function sale(?int $providerFee = null): Payment
    {
        $payment = $this->member->payments()->create([
            'type' => PaymentType::Subscription, 'status' => PaymentStatus::Succeeded, 'label' => 'Netflix', 'amount' => 2800,
            'service_fee' => 300, 'provider_fee' => $providerFee, 'months' => 1, 'method' => 'wave', 'phone' => '0700000001', 'confirmed_at' => now(),
        ]);
        $this->host->payments()->create([
            'type' => PaymentType::Earning, 'status' => PaymentStatus::Pending, 'source_payment_id' => $payment->id, 'label' => 'Gains',
            'amount' => 2375, 'gross' => 2500, 'months' => 1, 'method' => 'wave', 'available_at' => now()->addDay(),
        ]);

        return $payment;
    }

    public function test_net_profit_never_counts_owner_money_and_estimates_missing_fees(): void
    {
        $this->sale();
        // L'hôte retire 2 000 : 20 de frais facturés, 1 980 envoyés ; l'envoi a coûté 30 (réel).
        $this->host->payments()->create([
            'type' => PaymentType::Withdrawal, 'status' => PaymentStatus::Succeeded, 'label' => 'Retrait', 'amount' => 1980, 'service_fee' => 20,
            'provider_fee' => 30, 'method' => 'wave', 'phone' => '0700000002', 'confirmed_at' => now(),
        ]);

        $p = $this->getJson('/api/v1/admin/treasury?period=month')->assertOk()->json('profit');
        $this->assertSame(2800, $p['gross']);
        $this->assertSame(300 + 125 + 20, $p['revenue']);
        $this->assertSame(42, $p['feesIn']);           // 1,5 % de 2 800, estimé
        $this->assertSame(42, $p['feesInEstimated']);
        $this->assertSame(30, $p['feesOut']);          // réel, pas d'estimation en plus
        $this->assertSame(0, $p['feesOutEstimated']);
        $this->assertSame(445 - 42 - 30, $p['net']);
    }

    public function test_real_fee_replaces_the_estimate_without_double_counting(): void
    {
        $this->sale(providerFee: 42);
        $p = $this->getJson('/api/v1/admin/treasury')->json('profit');
        $this->assertSame(42, $p['feesIn']);
        $this->assertSame(0, $p['feesInEstimated']);
    }

    public function test_profit_withdrawal_is_cash_not_a_charge_and_counts_only_once_confirmed(): void
    {
        $this->sale(providerFee: 42);
        $w = $this->postJson('/api/v1/admin/treasury/withdrawals', [
            'amount' => 206, 'fee' => 6, 'destination' => 'Wave 0700000009', 'status' => 'pending', 'withdrawnAt' => now()->toDateTimeString(),
        ])->assertCreated()->assertJsonPath('data.net', 200)->json('data.id');

        $before = $this->getJson('/api/v1/admin/treasury')->json();
        $this->assertSame(0, $before['profit']['withdrawn']);
        $this->assertSame(206, $before['liabilities']['pendingWithdrawals']);

        $this->postJson("/api/v1/admin/treasury/withdrawals/{$w}/status", ['status' => 'confirmed'])->assertOk();
        $this->postJson("/api/v1/admin/treasury/withdrawals/{$w}/status", ['status' => 'failed'])->assertStatus(409);

        $after = $this->getJson('/api/v1/admin/treasury')->json();
        $this->assertSame(200, $after['profit']['withdrawn']);
        $this->assertSame(6, $after['profit']['withdrawalFees']);
        // Bénéfice : seuls les 6 de frais en moins.
        $this->assertSame($before['profit']['net'] - 6, $after['profit']['net']);
        $this->assertSame($before['cash']['theoretical'] - 206, $after['cash']['theoretical']);
    }

    public function test_no_withdrawable_amount_without_a_real_balance_then_capped_by_owner_money(): void
    {
        $this->sale(providerFee: 42);
        $data = $this->getJson('/api/v1/admin/treasury')->json();
        $this->assertNull($data['cash']['real']);
        $this->assertNull($data['available']['confirmed']);
        $this->assertNull($data['withdrawable']);
        // Calculé : 2 800 − 42 = 2 758 sur le compte ; 2 375 en séquestre pour l'hôte.
        $this->assertSame(2758, $data['cash']['theoretical']);
        $this->assertSame(2375, $data['liabilities']['ownersTotal']);

        $this->postJson('/api/v1/admin/treasury/balances', ['amount' => 2700, 'recordedAt' => now()->toDateTimeString()])->assertCreated();
        $data = $this->getJson('/api/v1/admin/treasury')->json();
        $this->assertSame(-58, $data['cash']['real']['gap']);
        $this->assertSame(2700 - 2375, $data['available']['confirmed']);
        // Bénéfice gagné : 300 + 125 − 42 = 383, mais seuls 325 sont réellement là.
        $this->assertSame(383, $data['profitLeft']);
        $this->assertSame(325, $data['withdrawable']);
    }

    public function test_entries_are_immutable_and_corrected_by_a_reversal(): void
    {
        $id = $this->postJson('/api/v1/admin/treasury/entries', ['kind' => 'charge', 'amount' => 5000, 'label' => 'Render', 'fromMerchant' => false, 'occurredAt' => now()->toDateTimeString()])
            ->assertCreated()->json('data.id');
        $this->assertSame(5000, $this->getJson('/api/v1/admin/treasury')->json('profit.charges'));
        // Payée hors du compte marchand : le solde calculé ne bouge pas.
        $this->assertSame(0, $this->getJson('/api/v1/admin/treasury')->json('cash.theoretical'));

        $this->postJson('/api/v1/admin/treasury/entries', ['kind' => 'charge', 'amount' => 1, 'label' => 'x', 'occurredAt' => now()->toDateTimeString(), 'reversesId' => $id])->assertCreated();
        $this->postJson('/api/v1/admin/treasury/entries', ['kind' => 'charge', 'amount' => 1, 'label' => 'x', 'occurredAt' => now()->toDateTimeString(), 'reversesId' => $id])->assertStatus(422);
        $this->assertSame(0, $this->getJson('/api/v1/admin/treasury')->json('profit.charges'));

        $this->expectException(\LogicException::class);
        TreasuryEntry::find($id)->update(['amount' => 1]);
    }

    public function test_jeko_sync_reads_the_real_balance_links_payments_and_classifies_a_withdrawal(): void
    {
        config(['services.payments.driver' => 'jeko', 'services.jeko.key' => 'k', 'services.jeko.key_id' => 'i', 'services.jeko.store_id' => 's']);
        $payment = $this->sale();
        Http::fake([
            '*/stores/s/balance' => Http::response(['amount' => 241200, 'currency' => 'XOF']),
            '*/transactions*' => Http::response(['total' => 2, 'perPage' => 100, 'currentPage' => 1, 'data' => [
                ['id' => 't-pay', 'type' => 'payment', 'status' => 'success', 'paymentMethod' => 'wave', 'amount' => ['amount' => 275800], 'fees' => ['amount' => 4200],
                    'counterpartIdentifier' => '+2250700000001', 'reference' => '111', 'createdAt' => now()->toIso8601String()],
                ['id' => 't-out', 'type' => 'transfer', 'status' => 'success', 'paymentMethod' => 'wave', 'amount' => ['amount' => 30000], 'fees' => ['amount' => 600],
                    'counterpartIdentifier' => '+2250500000000', 'reference' => '222', 'createdAt' => now()->toIso8601String()],
            ]]),
        ]);

        $this->postJson('/api/v1/admin/treasury/jeko/sync')->assertOk()->assertJsonPath('balance', 2412)->assertJsonPath('linked', 1);
        $this->assertSame(42, $payment->fresh()->provider_fee);
        $this->assertSame('t-pay', $payment->fresh()->provider_transaction_id);

        $data = $this->getJson('/api/v1/admin/treasury')->json();
        $this->assertSame('jeko', $data['cash']['real']['source']);
        $this->assertSame(1, $data['reconciliation']['unclassified']);

        $out = JekoTransaction::where('jeko_id', 't-out')->first();
        $this->postJson("/api/v1/admin/treasury/jeko/{$out->id}/classify", ['as' => 'withdrawal'])->assertOk();
        $this->postJson("/api/v1/admin/treasury/jeko/{$out->id}/classify", ['as' => 'ignore', 'reason' => 'x'])->assertStatus(409);

        $data = $this->getJson('/api/v1/admin/treasury')->json();
        $this->assertSame(300, $data['profit']['withdrawn']);
        $this->assertSame(6, $data['profit']['withdrawalFees']);
        // 2 800 − 42 − 306 = 2 452 calculé, 2 412 relevé : 40 d'écart affiché, jamais masqué.
        $this->assertSame(2452, $data['cash']['theoretical']);
        $this->assertSame(-40, $data['cash']['real']['gap']);
        $this->assertSame(0, $data['reconciliation']['unclassified']);

        $journal = $this->getJson('/api/v1/admin/treasury/journal')->assertOk()->json('data');
        $this->assertContains('withdrawal', array_column($journal, 'kind'));
        $this->assertNotContains('commission', array_column($journal, 'kind'));
    }

    public function test_payout_marked_paid_records_the_real_sending_fee(): void
    {
        $refund = $this->member->payments()->create([
            'type' => PaymentType::Refund, 'status' => PaymentStatus::Pending, 'label' => 'Remboursement', 'amount' => 1000, 'method' => 'wave', 'phone' => '0700000001',
        ]);
        $this->postJson("/api/v1/admin/payments/{$refund->id}/paid", ['fee' => 15])->assertOk();
        $this->assertSame(15, $refund->fresh()->provider_fee);
        $this->assertSame(15, $this->getJson('/api/v1/admin/treasury')->json('profit.feesOut'));
    }

    public function test_custom_period_and_validation(): void
    {
        $this->getJson('/api/v1/admin/treasury?period=custom&from=2026-01-01&to=2026-01-31')->assertOk()->assertJsonPath('preset', 'custom');
        $this->getJson('/api/v1/admin/treasury?period=custom')->assertStatus(422);
    }
}
