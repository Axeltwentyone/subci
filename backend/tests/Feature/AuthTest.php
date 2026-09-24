<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_otp_login_creates_account_and_returns_token(): void
    {
        config(['services.otp.expose_code' => true]);

        $code = $this->postJson('/api/v1/auth/otp', ['phone' => '0701020304'])->assertOk()->json('debugCode');

        $this->postJson('/api/v1/auth/verify', ['phone' => '0701020304', 'code' => '000000'])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Code incorrect ou expiré.');

        $res = $this->postJson('/api/v1/auth/verify', ['phone' => '0701020304', 'code' => $code])
            ->assertOk()->assertJsonPath('isNew', true)->assertJsonPath('user.phone', '0701020304');

        $this->withToken($res->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.phone', '0701020304');
        $this->assertNotNull(User::first()->referral_code);
    }

    public function test_code_cannot_be_reused(): void
    {
        config(['services.otp.expose_code' => true]);
        $code = $this->postJson('/api/v1/auth/otp', ['phone' => '0701020304'])->json('debugCode');
        $this->postJson('/api/v1/auth/verify', ['phone' => '0701020304', 'code' => $code])->assertOk();
        $this->postJson('/api/v1/auth/verify', ['phone' => '0701020304', 'code' => $code])->assertStatus(422);
    }

    public function test_api_requires_token_and_returns_json_401(): void
    {
        $this->getJson('/api/v1/bootstrap')->assertUnauthorized();
        $this->get('/api/v1/bootstrap')->assertUnauthorized();
    }

    public function test_first_and_last_name_are_required_together_and_normalized(): void
    {
        $user = User::factory()->create(['name' => null]);
        $this->actingAs($user);

        $this->patchJson('/api/v1/me', ['firstName' => 'Aya'])->assertStatus(422)->assertJsonValidationErrors('lastName');
        $this->patchJson('/api/v1/me', ['firstName' => 'Aya1', 'lastName' => 'Koné'])->assertStatus(422)->assertJsonValidationErrors('firstName');

        $this->patchJson('/api/v1/me', ['firstName' => '  aya ', 'lastName' => "n'guessan"])->assertOk()
            ->assertJsonPath('data.firstName', 'Aya')
            ->assertJsonPath('data.lastName', "N'Guessan")
            ->assertJsonPath('data.name', "Aya N'Guessan");
        $this->assertStringStartsWith('AYA-', $user->fresh()->referral_code);
    }
}
