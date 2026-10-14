<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZavuSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.zavu.key' => 'zv_test', 'services.zavu.sender' => '', 'services.zavu.send_in_local' => true,
            'services.whatsapp.token' => '', 'services.otp.beta_code' => null, 'services.otp.test_codes' => [],
        ]);
    }

    public function test_code_is_sent_by_sms_through_zavu(): void
    {
        Http::fake(['api.zavu.dev/*' => Http::response(['message' => ['id' => 'msg_1', 'status' => 'queued']], 202)]);

        $this->postJson('/api/v1/auth/otp', ['phone' => '0758421121'])->assertOk()->assertJsonPath('channel', 'sms');

        Http::assertSent(fn ($r) => $r->url() === 'https://api.zavu.dev/v1/messages' && $r->hasHeader('Authorization', 'Bearer zv_test')
            && $r['to'] === '+2250758421121' && $r['channel'] === 'sms_oneway'
            && preg_match('/^Sub\.ci : ton code est \d{6}\./', $r['text']) && strlen($r['text']) <= 160);
    }

    public function test_sms_takes_over_when_whatsapp_fails(): void
    {
        config(['services.whatsapp.token' => 'wa_test', 'services.whatsapp.phone_number_id' => '123456']);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Recipient not on WhatsApp', 'code' => 131026]], 400),
            'api.zavu.dev/*' => Http::response(['message' => ['id' => 'msg_1', 'status' => 'queued']], 202),
        ]);

        $this->postJson('/api/v1/auth/otp', ['phone' => '0758421121'])->assertOk()->assertJsonPath('channel', 'sms');
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.zavu.dev/'));
    }

    public function test_refused_sms_returns_a_clear_error(): void
    {
        Http::fake(['api.zavu.dev/*' => Http::response(['code' => 'insufficient_balance', 'message' => 'Insufficient balance'], 402)]);

        $this->postJson('/api/v1/auth/otp', ['phone' => '0758421121'])->assertStatus(503);
    }

    public function test_no_sms_in_local_unless_enabled(): void
    {
        Http::fake();
        config(['services.zavu.send_in_local' => false]);

        $this->postJson('/api/v1/auth/otp', ['phone' => '0758421121'])->assertOk();
        Http::assertNothingSent();
    }
}
