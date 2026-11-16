<?php

namespace Tests\Feature;

use App\Enums\PayMethod;
use App\Models\Admin;
use App\Models\User;
use App\Support\PayoutCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayoutCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_prefixes_and_formats(): void
    {
        $this->assertTrue(PayoutCheck::check(PayMethod::Wave, '0143998478')['ok']);
        $this->assertTrue(PayoutCheck::check(null, '0501020304')['defaulted']);
        $this->assertTrue(PayoutCheck::check(PayMethod::OrangeMoney, '0701020304')['ok']);
        $this->assertFalse(PayoutCheck::check(PayMethod::OrangeMoney, '0501020304')['ok']);
        $this->assertFalse(PayoutCheck::check(PayMethod::Mtn, '0701020304')['ok']);
        $this->assertTrue(PayoutCheck::check(PayMethod::Moov, '0101020304')['ok']);
        $this->assertFalse(PayoutCheck::check(PayMethod::Wave, '2721000000')['ok']);
        $this->assertFalse(PayoutCheck::check(PayMethod::Wave, null)['ok']);
        // Djamo : tout mobile ivoirien.
        $this->assertTrue(PayoutCheck::check(PayMethod::Djamo, '0501020304')['ok']);
        $this->assertFalse(PayoutCheck::check(PayMethod::Card, '0501020304')['ok']);
    }

    public function test_admin_sees_each_host_and_whether_jeko_knows_the_number(): void
    {
        config(['services.payments.driver' => 'jeko', 'services.jeko.key' => 'k', 'services.jeko.key_id' => 'i', 'services.jeko.store_id' => 's']);
        Http::fake(['*/partner_api/contacts' => Http::response([['id' => 'c1', 'name' => 'A', 'paymentMethod' => 'wave', 'identifier' => ['number' => '+2250701020304']]])]);
        $ok = User::factory()->create(['phone' => '0701020304', 'balance' => 5000]);
        $bad = User::factory()->create(['phone' => '0701020399', 'balance' => 3000, 'payout_method' => PayMethod::Mtn]);
        User::factory()->create(['balance' => 0]); // ni hôte ni solde : absent

        Admin::create(['name' => 'Axel', 'email' => 'admin@sub.ci', 'password' => 'un-mot-de-passe-long']);
        $this->withToken($this->postJson('/api/v1/admin/auth/login', ['email' => 'admin@sub.ci', 'password' => 'un-mot-de-passe-long'])->json('token'));

        $res = $this->getJson('/api/v1/admin/payouts/hosts')->assertOk()->assertJsonPath('summary.total', 2)->assertJsonPath('summary.ok', 1);
        $rows = collect($res->json('data'))->keyBy('id');
        $this->assertTrue($rows[$ok->id]['jekoContact']);
        $this->assertTrue($rows[$ok->id]['defaulted']);
        $this->assertFalse($rows[$bad->id]['ok']);
        $this->assertStringContainsString('05', $rows[$bad->id]['issues'][0]);
    }

    public function test_withdrawal_delay_is_configurable(): void
    {
        config(['services.payments.manual_payouts' => true, 'services.payments.withdrawal_delay_hours' => 2]);
        $host = User::factory()->create(['phone' => '0701020304', 'balance' => 5000]);
        $this->actingAs($host)->postJson('/api/v1/host/withdrawals', ['amount' => 2000])->assertSuccessful();
        $this->assertStringContainsString('sous 2 h', $host->notifications()->latest()->first()->data['body'] ?? json_encode($host->notifications()->latest()->first()->data));
    }
}
