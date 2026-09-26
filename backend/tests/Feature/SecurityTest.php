<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Admin;
use App\Models\HostOffer;
use App\Models\JoinRequest;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\EarningService;
use App\Services\OtpService;
use App\Services\PaymentService;
use App\Support\Totp;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Correctifs de l'audit de sécurité : un test par faille corrigée. */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServiceSeeder::class);
        config(['services.payments.fake_delay' => 0]);
        $this->host = User::factory()->create(['first_name' => 'Koffi', 'last_name' => 'Yao']);
    }

    private function offer(array $attrs = []): HostOffer
    {
        return $this->host->hostOffers()->create($attrs + [
            'service_id' => Service::where('slug', 'netflix')->value('id'), 'plan' => 'premium', 'plan_label' => 'Premium · 4 écrans',
            'devices' => ['tv'], 'seats' => 3, 'price' => 2400, 'access_mode' => 'credentials',
            'access_email' => 'koffi@mail.ci', 'access_password' => 'host-secret', 'status' => 'live', 'approved_at' => now(),
        ]);
    }

    /** Membre qui paie $months mois et que l'hôte accepte. */
    private function joined(HostOffer $offer, int $months = 3): User
    {
        $member = User::factory()->create(['first_name' => 'Aya', 'last_name' => 'Koné']);
        $this->actingAs($member);
        $ref = $this->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $offer->id, 'months' => $months, 'method' => 'wave', 'phone' => '0758421121'])
            ->assertCreated()->json('data.ref');
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'succeeded');
        $this->actingAs($this->host)->postJson('/api/v1/host/requests/'.JoinRequest::latest('id')->value('id').'/accept')->assertOk();

        return $member;
    }

    // 1 · Fraude hôte : séquestre, retrait de membre, litige.

    public function test_host_removing_a_member_refunds_the_months_not_yet_paid_out(): void
    {
        $offer = $this->offer();
        $member = $this->joined($offer, 3);
        $this->travel(73)->hours();
        app(EarningService::class)->release();
        $this->assertSame(2166, $this->host->fresh()->balance);

        $this->actingAs($this->host)->deleteJson("/api/v1/host/offers/{$offer->id}/members/".$offer->members()->value('id'))->assertOk();

        // Mois 2 et 3 rendus au membre (2 × 2 280), jamais versés à l'hôte.
        $this->assertSame(4560, $member->payments()->where('type', 'refund')->value('amount'));
        $this->assertSame(0, Payment::escrowed()->count());
        $sub = $member->subscriptions()->sole();
        $this->assertNull($sub->host_offer_id);
        $this->assertTrue($sub->ends_at->lte(now()->addMonth()));
        $this->travel(2)->months();
        app(EarningService::class)->release();
        $this->assertSame(2166, $this->host->fresh()->balance);
    }

    public function test_dispute_freezes_host_earnings_until_solved_or_refunded(): void
    {
        $offer = $this->offer();
        $member = $this->joined($offer, 1);
        $sub = $member->subscriptions()->sole();

        $this->actingAs($member)->postJson("/api/v1/subscriptions/{$sub->id}/dispute", ['reason' => 'wrong_password', 'message' => 'Le mot de passe ne marche plus'])
            ->assertOk()->assertJsonPath('data.dispute.reason', 'wrong_password');
        $this->postJson("/api/v1/subscriptions/{$sub->id}/dispute", ['reason' => 'other'])->assertStatus(422);

        $this->travel(4)->days();
        app(EarningService::class)->release();
        $this->assertSame(0, $this->host->fresh()->balance, 'gelé tant que le souci est ouvert');

        // Décision admin : remboursement du temps non versé.
        $admin = Admin::create(['name' => 'A', 'email' => 'a@sub.ci', 'password' => 'un-mot-de-passe-long']);
        $token = $admin->createToken('admin', ['admin'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $id = $this->withToken($token)->getJson('/api/v1/admin/disputes')->assertOk()->assertJsonPath('data.0.refundable', 2400)->json('data.0.id');
        $this->withToken($token)->postJson("/api/v1/admin/disputes/{$id}/resolve", ['decision' => 'refund'])->assertOk()->assertJsonPath('data.status', 'refunded');

        $this->assertSame(2400, $member->payments()->where('type', 'refund')->value('amount'));
        $this->assertSame(0, $this->host->fresh()->balance);
        $this->assertNull($sub->fresh()->host_offer_id);
        $this->assertSame(0, $offer->members()->count());
    }

    public function test_new_host_is_paid_48h_after_each_month_starts_trusted_host_24h(): void
    {
        $this->joined($this->offer(), 1);
        $this->travel(25)->hours();
        app(EarningService::class)->release();
        $this->assertSame(0, $this->host->fresh()->balance);
        $this->travel(24)->hours();
        app(EarningService::class)->release();
        $this->assertSame(2280, $this->host->fresh()->balance);

        // Hôte fiable : en ligne depuis 3 mois, 3 mois déjà versés, aucun souci.
        $trusted = User::factory()->create();
        $offer = $trusted->hostOffers()->create([
            'service_id' => Service::where('slug', 'netflix')->value('id'), 'plan' => 'premium', 'plan_label' => 'Premium · 4 écrans',
            'devices' => ['tv'], 'seats' => 3, 'price' => 2400, 'access_mode' => 'credentials', 'status' => 'live', 'approved_at' => now()->subDays(100),
        ]);
        foreach (range(1, 3) as $i) {
            $trusted->payments()->create(['type' => 'earning', 'status' => 'succeeded', 'label' => 'Gains', 'amount' => 2160, 'method' => 'wave', 'host_offer_id' => $offer->id]);
        }
        $this->assertTrue($trusted->isTrustedHost());
        $this->host = $trusted;
        $this->joined($offer, 1);
        $this->assertTrue($this->actingAs($trusted)->getJson('/api/v1/host')->json('data.trusted'));
        $this->travel(25)->hours();
        app(EarningService::class)->release();
        $this->assertSame(2280, $trusted->fresh()->balance);
    }

    public function test_host_history_shows_earnings_once_paid_out_and_admin_sees_the_split(): void
    {
        $member = $this->joined($this->offer(), 3);
        $payment = $member->payments()->where('type', 'subscription')->sole();

        // Historique de l'hôte : rien tant que le mois n'est pas versé (c'est dans « à venir »).
        $this->actingAs($this->host)->getJson('/api/v1/payments')->assertJsonCount(0, 'data');
        $this->travel(49)->hours();
        app(EarningService::class)->release();
        $this->getJson('/api/v1/payments')->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', 2166);

        $admin = Admin::create(['name' => 'A', 'email' => 'a@sub.ci', 'password' => 'un-mot-de-passe-long']);
        $this->app['auth']->forgetGuards();
        $split = $this->withToken($admin->createToken('admin', ['admin'])->plainTextToken)
            ->getJson("/api/v1/admin/payments/{$payment->id}")->assertOk()->json('data.split');
        $this->assertSame(342, $split['commission']);       // 6 840 − 3 × 2 166
        $this->assertCount(3, $split['installments']);
        $this->assertSame(['succeeded', 'pending', 'pending'], array_column($split['installments'], 'status'));
    }

    public function test_member_can_close_their_dispute_and_earnings_resume(): void
    {
        $member = $this->joined($this->offer(), 1);
        $sub = $member->subscriptions()->sole();
        $this->actingAs($member)->postJson("/api/v1/subscriptions/{$sub->id}/dispute", ['reason' => 'no_access'])->assertOk();
        $this->postJson("/api/v1/subscriptions/{$sub->id}/dispute/solve")->assertOk()->assertJsonPath('data.dispute', null);

        $this->travel(4)->days();
        app(EarningService::class)->release();
        $this->assertSame(2280, $this->host->fresh()->balance);
    }

    public function test_removed_member_cannot_renew_without_a_host(): void
    {
        $offer = $this->offer();
        $member = $this->joined($offer, 1);
        $this->actingAs($this->host)->deleteJson("/api/v1/host/offers/{$offer->id}/members/".$offer->members()->value('id'))->assertOk();

        $this->actingAs($member)->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'months' => 1, 'method' => 'wave', 'phone' => '0758421121'])
            ->assertStatus(422)->assertJsonValidationErrors('offerId');
    }

    public function test_accept_refuses_when_offer_is_already_full(): void
    {
        $offer = $this->offer(['seats' => 1]);
        $this->joined($offer, 1);
        // Demande créée « à la main » (course entre deux achats) alors que la place est prise.
        $late = User::factory()->create();
        $payment = $late->payments()->create(['type' => 'subscription', 'status' => 'succeeded', 'host_offer_id' => $offer->id, 'service_id' => $offer->service_id, 'label' => 'x', 'amount' => 2400, 'months' => 1, 'method' => 'wave']);
        $request = JoinRequest::create(['host_offer_id' => $offer->id, 'user_id' => $late->id, 'payment_id' => $payment->id, 'status' => 'pending', 'expires_at' => now()->addDay()]);

        $this->actingAs($this->host)->postJson("/api/v1/host/requests/{$request->id}/accept")->assertStatus(409);
    }

    // 2 · Code SMS : plafonds par numéro.

    public function test_otp_is_locked_after_too_many_wrong_codes_whatever_the_ip(): void
    {
        config(['services.otp.max_failures' => 6, 'services.otp.per_hour' => 100, 'services.otp.per_day' => 100]);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/auth/otp', ['phone' => '0701020304'])->assertOk();
            for ($j = 0; $j < 3; $j++) {
                $this->withServerVariables(['REMOTE_ADDR' => "10.0.{$i}.{$j}"])
                    ->postJson('/api/v1/auth/verify', ['phone' => '0701020304', 'code' => '000000'])->assertStatus(422);
            }
        }
        $code = $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->postJson('/api/v1/auth/otp', ['phone' => '0701020304']);
        $code->assertStatus(429);
    }

    public function test_test_numbers_log_in_with_their_fixed_code_only(): void
    {
        config(['services.otp.expose_code' => false, 'services.otp.test_codes' => ['0700000009' => '482913']]);

        $this->postJson('/api/v1/auth/otp', ['phone' => '0700000009'])->assertOk()->assertJsonPath('debugCode', null);
        $this->postJson('/api/v1/auth/verify', ['phone' => '0700000009', 'code' => '000000'])->assertStatus(422);
        $this->postJson('/api/v1/auth/verify', ['phone' => '0700000009', 'code' => '482913'])->assertOk()->assertJsonStructure(['token']);

        // Un autre numéro n'a pas ce code.
        $this->postJson('/api/v1/auth/otp', ['phone' => '0700000008'])->assertOk();
        $this->postJson('/api/v1/auth/verify', ['phone' => '0700000008', 'code' => '482913'])->assertStatus(422);
    }

    public function test_beta_code_works_for_any_number_until_its_end_date_only(): void
    {
        config(['services.otp.expose_code' => false, 'services.otp.beta_code' => '246810', 'services.otp.beta_until' => now()->addDays(3)->toDateString()]);
        $this->postJson('/api/v1/auth/otp', ['phone' => '0711223344'])->assertOk()->assertJsonPath('debugCode', null);
        $this->postJson('/api/v1/auth/verify', ['phone' => '0711223344', 'code' => '246810'])->assertOk();

        // Après la date de fin : coupé automatiquement.
        $this->travel(4)->days();
        $this->postJson('/api/v1/auth/otp', ['phone' => '0711223355'])->assertOk();
        $this->postJson('/api/v1/auth/verify', ['phone' => '0711223355', 'code' => '246810'])->assertStatus(422);

        // Sans date de fin : jamais actif.
        config(['services.otp.beta_until' => null]);
        $this->assertNull(OtpService::betaCode());
    }

    public function test_otp_sends_are_capped_per_hour(): void
    {
        config(['services.otp.per_hour' => 3]);
        foreach (range(1, 3) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.{$i}"])->postJson('/api/v1/auth/otp', ['phone' => '0701020305'])->assertOk();
            $this->travel(61)->seconds();
        }
        $this->postJson('/api/v1/auth/otp', ['phone' => '0701020305'])->assertStatus(429);
    }

    // 3 · Numéro de retrait.

    public function test_changing_payout_number_needs_sms_code_and_locks_withdrawals(): void
    {
        config(['services.otp.expose_code' => true]);
        $this->host->forceFill(['balance' => 10000])->save();
        $this->actingAs($this->host);

        $this->patchJson('/api/v1/me', ['payout' => ['method' => 'wave', 'phone' => '0799999999']])->assertStatus(422)->assertJsonValidationErrors('payout.code');
        $this->patchJson('/api/v1/me', ['payout' => ['method' => 'wave', 'phone' => '0799999999', 'code' => '123456']])->assertStatus(422);

        $code = $this->postJson('/api/v1/me/payout/code')->assertOk()->json('debugCode');
        $this->patchJson('/api/v1/me', ['payout' => ['method' => 'wave', 'phone' => '0799999999', 'code' => $code]])->assertOk()->assertJsonPath('data.payout.phone', '0799999999');

        $this->postJson('/api/v1/host/withdrawals', ['amount' => 5000])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertNotNull($this->getJson('/api/v1/host')->json('data.withdrawLockedUntil'));
        $this->travel(25)->hours();
        $this->postJson('/api/v1/host/withdrawals', ['amount' => 5000])->assertOk();
    }

    // 4 · Paiements annulés, relancés, en double.

    public function test_payment_cancelled_in_app_but_paid_later_is_still_confirmed(): void
    {
        config(['services.payments.fake_delay' => 3600]);
        $offer = $this->offer();
        $member = User::factory()->create();
        $ref = $this->actingAs($member)->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $offer->id, 'months' => 1, 'method' => 'wave', 'phone' => '0758421121'])->json('data.ref');
        $this->postJson("/api/v1/payments/{$ref}/cancel")->assertJsonPath('data.status', 'failed');

        config(['services.payments.fake_delay' => 0]);
        $this->app->forgetInstance(PaymentGateway::class);
        app(PaymentService::class)->reconcile();

        $this->assertSame(PaymentStatus::Succeeded, Payment::where('reference', $ref)->value('status'));
        $this->assertSame(1, JoinRequest::count());
    }

    public function test_resend_keeps_watching_the_first_request_and_refunds_a_double_payment(): void
    {
        config(['services.payments.fake_delay' => 3600]);
        $offer = $this->offer();
        $member = User::factory()->create();
        $ref = $this->actingAs($member)->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $offer->id, 'months' => 1, 'method' => 'wave', 'phone' => '0758421121'])->json('data.ref');
        $this->postJson("/api/v1/payments/{$ref}/resend")->assertOk();
        $payment = Payment::where('reference', $ref)->first();
        $this->assertSame(2, $payment->references()->count());

        // Les deux demandes ont été validées par le membre.
        config(['services.payments.fake_delay' => 0]);
        $this->app->forgetInstance(PaymentGateway::class);
        app(PaymentService::class)->reconcile();

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(2400, $member->payments()->where('type', 'refund')->value('amount'));
        app(PaymentService::class)->reconcile();
        $this->assertSame(1, $member->payments()->where('type', 'refund')->count(), 'remboursé une seule fois');
    }

    // 5 · SSRF via l'adresse push.

    public function test_push_endpoint_must_be_a_real_push_service(): void
    {
        $this->actingAs(User::factory()->create());
        $keys = ['keys' => ['p256dh' => 'k', 'auth' => 'a']];
        foreach (['https://169.254.169.254/latest/meta-data', 'https://fcm.googleapis.com.evil.io/x', 'https://fcm.googleapis.com:8443/x', 'http://fcm.googleapis.com/x', 'https://internal.local/x'] as $bad) {
            $this->postJson('/api/v1/push/subscriptions', ['endpoint' => $bad] + $keys)->assertStatus(422);
        }
        $this->postJson('/api/v1/push/subscriptions', ['endpoint' => 'https://web.push.apple.com/QGx'] + $keys)->assertCreated();
    }

    // 6 · Polling limité.

    public function test_polling_does_not_hammer_the_gateway(): void
    {
        config(['services.payments.poll_interval' => 5, 'services.payments.fake_delay' => 3600]);
        $gateway = new class implements PaymentGateway
        {
            public int $calls = 0;

            public function request(Payment $payment): array
            {
                return ['reference' => 'R-'.$payment->id, 'url' => null];
            }

            public function status(Payment $payment, ?string $reference = null): PaymentStatus
            {
                $this->calls++;

                return PaymentStatus::Pending;
            }
        };
        $this->app->instance(PaymentGateway::class, $gateway);
        $ref = $this->actingAs(User::factory()->create())->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $this->offer()->id, 'months' => 1, 'method' => 'wave', 'phone' => '0758421121'])->json('data.ref');
        foreach (range(1, 10) as $i) {
            $this->getJson("/api/v1/payments/{$ref}")->assertOk();
        }
        $this->assertSame(1, $gateway->calls);
    }

    // 7 · Admin : double authentification, blocage par e-mail.

    public function test_admin_must_enroll_two_factor_then_use_it(): void
    {
        config(['services.admin.require_2fa' => true]);
        Admin::create(['name' => 'Axel', 'email' => 'admin@sub.ci', 'password' => 'un-mot-de-passe-long']);
        $login = fn () => $this->postJson('/api/v1/admin/auth/login', ['email' => 'admin@sub.ci', 'password' => 'un-mot-de-passe-long']);

        // 1re connexion : jeton limité à la configuration.
        $setup = $login()->assertOk()->assertJsonPath('setup', true)->json('token');
        $this->withToken($setup)->getJson('/api/v1/admin/overview')->assertForbidden();
        $secret = $this->withToken($setup)->postJson('/api/v1/admin/auth/2fa/setup')->assertOk()->json('secret');
        $this->withToken($setup)->postJson('/api/v1/admin/auth/2fa/confirm', ['code' => '000000'])->assertStatus(422);
        $full = $this->withToken($setup)->postJson('/api/v1/admin/auth/2fa/confirm', ['code' => Totp::code($secret, intdiv(now()->getTimestamp(), 30))])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($full)->getJson('/api/v1/admin/overview')->assertOk();

        // Connexions suivantes : mot de passe, puis code (un code déjà utilisé est refusé).
        $this->travel(31)->seconds();
        $challenge = $login()->assertOk()->assertJsonPath('twoFactor', true)->assertJsonMissingPath('token')->json('challenge');
        $code = Totp::code($secret, intdiv(now()->getTimestamp(), 30));
        $this->postJson('/api/v1/admin/auth/2fa', ['challenge' => $challenge, 'code' => $code])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/v1/admin/auth/2fa', ['challenge' => $challenge, 'code' => $code])->assertStatus(422);
        $this->postJson('/api/v1/admin/auth/2fa', ['challenge' => 'faux', 'code' => $code])->assertStatus(422);
    }

    public function test_admin_login_is_locked_per_email_even_across_ips(): void
    {
        Admin::create(['name' => 'Axel', 'email' => 'admin@sub.ci', 'password' => 'un-mot-de-passe-long']);
        foreach (range(1, 10) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.2.0.{$i}"])
                ->postJson('/api/v1/admin/auth/login', ['email' => 'admin@sub.ci', 'password' => 'mauvais'])->assertStatus(422);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.2.1.1'])
            ->postJson('/api/v1/admin/auth/login', ['email' => 'admin@sub.ci', 'password' => 'un-mot-de-passe-long'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', fn ($m) => str_starts_with($m, 'Trop de tentatives'));
    }

    // 8 · Sessions membres.

    public function test_member_session_expires_and_other_devices_can_be_logged_out(): void
    {
        config(['services.otp.expose_code' => true]);
        $login = function () {
            $code = $this->postJson('/api/v1/auth/otp', ['phone' => '0701020304'])->json('debugCode');

            return $this->postJson('/api/v1/auth/verify', ['phone' => '0701020304', 'code' => $code])->json('token');
        };
        $phone = $login();
        $laptop = $login();
        $user = User::where('phone', '0701020304')->first();
        $this->assertTrue($user->tokens()->first()->expires_at->between(now()->addDays(89), now()->addDays(91)));

        $this->withToken($phone)->postJson('/api/v1/auth/logout-others')->assertOk()->assertJsonPath('revoked', 1);
        $this->app['auth']->forgetGuards();
        $this->withToken($laptop)->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/v1/me')->assertOk();
    }

    // 11 · En-têtes.

    public function test_api_sends_security_headers(): void
    {
        $this->getJson('/api/v1/services')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeaderMissing('X-Powered-By');
    }

    // 15 · Métadonnées des captures.

    public function test_proof_screenshot_is_stripped_of_metadata(): void
    {
        Storage::fake();
        $img = imagecreatetruecolor(40, 40);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        // Segment EXIF (APP1) avec une position GPS factice, inséré après SOI.
        $exif = "Exif\0\0GPS 5.3364N 4.0267W";
        $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $file = UploadedFile::fake()->createWithContent('compte.jpg', $jpeg);

        $this->actingAs($this->host)->post('/api/v1/host/offers', [
            'serviceId' => 'netflix', 'plan' => 'premium', 'seats' => 3, 'price' => 2400, 'devices' => ['tv'],
            'email' => 'koffi@mail.ci', 'password' => 'host-secret', 'proof' => $file,
        ], ['Accept' => 'application/json'])->assertCreated();

        $stored = Storage::get(HostOffer::latest('id')->value('proof_path'));
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertStringNotContainsString('GPS', $stored);
    }
}
