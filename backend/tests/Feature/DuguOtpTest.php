<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Services\DuguOtp;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DuguOtpTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '0758421121';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.dugu.key' => 'dsk_live_test', 'services.dugu.send_in_local' => true,
            'services.zavu.key' => 'zv_test', 'services.zavu.sender' => '', 'services.zavu.send_in_local' => true,
            'services.whatsapp.token' => '', 'services.otp.beta_code' => null, 'services.otp.test_codes' => [], 'services.otp.expose_code' => true,
        ]);
    }

    private function accepted(array $data = []): PromiseInterface
    {
        return Http::response(['data' => ['otp_id' => 'otp_1', 'status' => 'sent', 'channel' => 'whatsapp'] + $data]);
    }

    public function test_code_is_requested_then_verified_by_dugu(): void
    {
        Http::fake([
            'dugusender.com/api/v1/otp/request' => $this->accepted(),
            'dugusender.com/api/v1/otp/verify' => Http::response(['data' => ['otp_id' => 'otp_1', 'status' => 'verified']]),
        ]);

        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])->assertOk()->assertJsonPath('channel', 'whatsapp');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/otp/request') && $r->hasHeader('Authorization', 'Bearer dsk_live_test')
            && $r['subject'] === '+2250758421121' && $r['channel'] === 'whatsapp' && $r['purpose_type'] === 'verification_phone');

        $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => '483921'])->assertOk()->assertJsonPath('user.phone', self::PHONE);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/otp/verify') && $r['otp_id'] === 'otp_1' && $r['code'] === '483921');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'zavu'));
    }

    public function test_code_refused_by_dugu_is_rejected_and_counted(): void
    {
        Http::fake([
            'dugusender.com/api/v1/otp/request' => $this->accepted(),
            'dugusender.com/api/v1/otp/verify' => Http::response(['error' => ['code' => 'invalid_code']], 422),
        ]);
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])->assertOk();

        $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => '000000'])->assertStatus(422);
        $this->assertSame(1, OtpCode::first()->attempts);
    }

    public function test_unknown_verify_status_never_logs_in(): void
    {
        Http::fake([
            'dugusender.com/api/v1/otp/request' => $this->accepted(),
            'dugusender.com/api/v1/otp/verify' => Http::response(['data' => ['otp_id' => 'otp_1', 'status' => 'pending']]),
        ]);
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])->assertOk();

        $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => '483921'])->assertStatus(422);
    }

    public function test_dugu_outage_on_verify_is_a_503_not_a_wrong_code(): void
    {
        Http::fake([
            'dugusender.com/api/v1/otp/request' => $this->accepted(),
            'dugusender.com/api/v1/otp/verify' => Http::response([], 500),
        ]);
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])->assertOk();

        $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => '483921'])->assertStatus(503);
        $this->assertSame(0, OtpCode::first()->attempts);
    }

    public function test_sms_zavu_takes_over_and_code_is_checked_locally_when_dugu_refuses(): void
    {
        Http::fake([
            'dugusender.com/*' => Http::response(['error' => ['code' => 'insufficient_wallet_balance']], 402),
            'api.zavu.dev/*' => Http::response(['message' => ['id' => 'msg_1', 'status' => 'queued']], 202),
        ]);

        $code = $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])->assertOk()->assertJsonPath('channel', 'sms')->json('debugCode');
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.zavu.dev/') && str_contains($r['text'], $code));

        $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => $code])->assertOk();
    }

    public function test_clear_error_when_dugu_refuses_and_there_is_no_sms(): void
    {
        config(['services.zavu.key' => '']);
        Http::fake(['dugusender.com/*' => Http::response(['error' => ['code' => 'pricing_not_configured']], 422)]);

        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])->assertStatus(503);
    }

    public function test_sandbox_code_is_exposed_in_local(): void
    {
        Http::fake([
            'dugusender.com/api/v1/otp/request' => $this->accepted(['sandbox' => ['test_code' => '112233']]),
        ]);

        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])->assertOk()->assertJsonPath('debugCode', '112233');
    }

    public function test_sandbox_key_is_ignored_in_production_and_nothing_is_sent_in_local_by_default(): void
    {
        config(['services.dugu.key' => 'dsk_test_x']);
        $this->app['env'] = 'production';
        $this->assertFalse(DuguOtp::enabled());
        config(['services.dugu.key' => 'dsk_live_x']);
        $this->assertTrue(DuguOtp::enabled());

        $this->app['env'] = 'local';
        config(['services.dugu.send_in_local' => false]);
        $this->assertFalse(DuguOtp::enabled());
    }
}
