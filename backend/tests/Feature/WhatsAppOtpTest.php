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

    public function test_meta_can_verify_the_webhook_url_only_with_the_right_token(): void
    {
        config(['services.whatsapp.webhook_verify_token' => 'jeton-sub-ci']);

        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=faux&hub.challenge=123')->assertForbidden();
        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=jeton-sub-ci&hub.challenge=987654')
            ->assertOk()->assertSeeText('987654');
    }

    public function test_webhook_events_need_a_valid_signature_when_app_secret_is_set(): void
    {
        config(['services.whatsapp.app_secret' => 'app-secret']);
        $body = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['status' => 'failed', 'errors' => [['code' => 131026, 'title' => 'Undeliverable']]]]]]]]]]);

        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=faux'], $body)->assertStatus(401);
        $sig = 'sha256='.hash_hmac('sha256', $body, 'app-secret');
        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sig], $body)->assertOk();
    }

    public function test_admin_creates_the_authentication_template_through_the_api(): void
    {
        config(['services.whatsapp.waba_id' => '999', 'services.admin.require_2fa' => false]);
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'tpl_1', 'status' => 'APPROVED', 'category' => 'AUTHENTICATION'])]);
        \App\Models\Admin::create(['name' => 'A', 'email' => 'wa@sub.ci', 'password' => 'un-mot-de-passe-long']);
        $token = $this->postJson('/api/v1/admin/auth/login', ['email' => 'wa@sub.ci', 'password' => 'un-mot-de-passe-long'])->json('token');

        $this->withToken($token)->postJson('/api/v1/admin/whatsapp/template')->assertOk()->assertJsonPath('status', 'APPROVED');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/999/message_templates') && $r['category'] === 'AUTHENTICATION'
            && $r['name'] === 'code_connexion' && $r['language'] === 'fr' && $r['components'][2]['buttons'][0]['otp_type'] === 'COPY_CODE');
    }
}
