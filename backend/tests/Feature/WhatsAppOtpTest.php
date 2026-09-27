<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.token' => 'wa_test', 'services.whatsapp.phone_number_id' => '123456', 'services.otp.beta_code' => null, 'services.otp.test_codes' => []]);
    }

    public function test_code_is_sent_with_the_whatsapp_authentication_template(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $this->postJson('/api/v1/auth/otp', ['phone' => '0758421121'])->assertOk()->assertJsonPath('channel', 'whatsapp');

        Http::assertSent(function ($r) {
            $code = $r['template']['components'][0]['parameters'][0]['text'];

            return str_ends_with($r->url(), '/123456/messages') && $r->hasHeader('Authorization', 'Bearer wa_test')
                && $r['to'] === '2250758421121' && $r['template']['name'] === 'code_connexion' && $r['template']['language']['code'] === 'fr'
                && preg_match('/^\d{6}$/', $code) && $r['template']['components'][1]['parameters'][0]['text'] === $code;
        });
    }

    public function test_failed_whatsapp_send_returns_a_clear_error(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Recipient not on WhatsApp', 'code' => 131026]], 400)]);

        $this->postJson('/api/v1/auth/otp', ['phone' => '0758421121'])->assertStatus(503);
    }

    public function test_beta_code_sends_nothing(): void
    {
        Http::fake();
        config(['services.otp.beta_code' => '246810', 'services.otp.beta_until' => now()->addDay()->toDateString()]);

        $this->postJson('/api/v1/auth/otp', ['phone' => '0758421121'])->assertOk();
        Http::assertNothingSent();
    }
}
