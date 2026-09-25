<?php

namespace Tests\Feature;

use App\Models\HostOffer;
use App\Models\JoinRequest;
use App\Models\Service;
use App\Models\User;
use App\Services\EarningService;
use App\Services\InviteReminder;
use App\Services\JoinService;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CheckoutTest extends TestCase
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

    private function offer(string $slug = 'netflix', array $attrs = []): HostOffer
    {
        return $this->host->hostOffers()->create($attrs + [
            'service_id' => Service::where('slug', $slug)->value('id'), 'plan' => 'premium', 'plan_label' => 'Premium · 4 écrans',
            'devices' => ['phone', 'tv'], 'quality' => '4K', 'seats' => 3, 'price' => 2400,
            'access_mode' => 'credentials', 'access_email' => 'koffi@mail.ci', 'access_password' => 'host-secret',
            'status' => 'live', 'approved_at' => now(),
        ]);
    }

    /** Paie une place dans l'offre et renvoie la demande créée. */
    private function payFor(HostOffer $offer, int $months = 1): JoinRequest
    {
        $ref = $this->postJson('/api/v1/payments', ['serviceId' => $offer->service->slug, 'offerId' => $offer->id, 'months' => $months, 'method' => 'om', 'phone' => '0758421121'])
            ->assertCreated()->json('data.ref');
        $this->getJson("/api/v1/payments/{$ref}")->assertOk()->assertJsonPath('data.status', 'succeeded');

        return JoinRequest::latest('id')->firstOrFail();
    }

    private function asHost(): static
    {
        return $this->actingAs($this->host);
    }

    public function test_member_sees_offers_with_devices_and_host(): void
    {
        $this->offer();
        $this->offer('netflix', ['plan' => 'standard', 'plan_label' => 'Standard · 2 écrans', 'devices' => ['phone'], 'price' => 2000, 'seats' => 1]);
        $this->offer('netflix', ['status' => 'review', 'approved_at' => null]);

        // Prénoms des hôtes et des membres : jamais sans connexion.
        $this->getJson('/api/v1/services/netflix/offers')->assertUnauthorized();

        $offers = $this->actingAs(User::factory()->create())->getJson('/api/v1/services/netflix/offers')->assertOk()->json('data');
        $this->assertCount(2, $offers);
        $this->assertSame(2000, $offers[0]['price']); // moins chère d'abord
        $this->assertSame(['phone'], $offers[0]['devices']);
        $this->assertSame('Koffi Y.', $offers[0]['host']['name']);
        $this->assertArrayNotHasKey('access_email', $offers[0]);

        $this->asHost()->getJson('/api/v1/services/netflix/offers')->assertJsonCount(0, 'data');
    }

    public function test_paid_request_waits_for_host_then_accept_gives_access_and_pays_host(): void
    {
        $offer = $this->offer();
        $member = User::factory()->create(['first_name' => 'Aya', 'last_name' => 'Koné']);
        $this->actingAs($member);

        $request = $this->payFor($offer, 3);
        $this->assertSame('pending', $request->status->value);
        $this->assertSame(0, $member->subscriptions()->count());
        $this->assertSame(0, $this->host->fresh()->balance);

        $requests = $this->asHost()->getJson('/api/v1/host')->json('data.offers.0.requests');
        $this->assertSame('Aya K.', $requests[0]['member']['name']);
        $this->assertSame(0, $requests[0]['member']['removalsCount']);
        $this->assertSame(6840, $requests[0]['amount']);

        $this->postJson("/api/v1/host/requests/{$request->id}/accept")->assertOk()->assertJsonCount(1, 'data.members')->assertJsonCount(0, 'data.requests');
        $sub = $member->subscriptions()->sole();
        $this->assertSame('active', $sub->status->value);
        $this->assertSame('host-secret', $sub->access_password);
        $this->assertNotSame('host-secret', DB::table('subscriptions')->value('access_password'));
        // Séquestre : 3 gains de 2 052 (2 280 − 10 %), un par mois, chacun disponible 72 h après le début de son mois.
        $this->assertSame(0, $this->host->fresh()->balance);
        $this->assertSame(6156, $this->getJson('/api/v1/host')->json('data.pending'));
        $this->postJson("/api/v1/host/requests/{$request->id}/accept")->assertStatus(409);

        $this->travel(73)->hours();
        app(EarningService::class)->release();
        $this->assertSame(2052, $this->host->fresh()->balance);
        $this->travel(1)->months();
        app(EarningService::class)->release();
        $this->assertSame(4104, $this->host->fresh()->balance);
    }

    public function test_decline_refunds_member_and_frees_seat(): void
    {
        $offer = $this->offer('netflix', ['seats' => 1]);
        $member = User::factory()->create();
        $this->actingAs($member);
        $request = $this->payFor($offer);
        $this->assertSame([], $this->getJson('/api/v1/services/netflix/offers')->json('data'));

        $this->asHost()->postJson("/api/v1/host/requests/{$request->id}/decline")->assertOk();

        $this->assertSame('declined', $request->fresh()->status->value);
        $this->assertNotNull($request->payment->fresh()->refunded_at);
        $this->assertSame(2400, $member->payments()->where('type', 'refund')->value('amount'));
        $this->assertSame(0, $member->subscriptions()->count());
        $this->assertSame(0, $this->host->fresh()->balance);
        $this->actingAs($member);
        $this->assertSame(1, $this->getJson('/api/v1/services/netflix/offers')->json('data.0.free'));
    }

    public function test_no_answer_after_24h_is_refunded(): void
    {
        $offer = $this->offer();
        $this->actingAs(User::factory()->create());
        $request = $this->payFor($offer);

        $this->travel(25)->hours();
        app(JoinService::class)->expireOverdue();

        $this->assertSame('expired', $request->fresh()->status->value);
        $this->assertNotNull($request->payment->fresh()->refunded_at);
    }

    public function test_member_can_cancel_pending_request_but_not_twice(): void
    {
        $offer = $this->offer();
        $member = User::factory()->create();
        $this->actingAs($member);
        $request = $this->payFor($offer);

        $this->postJson("/api/v1/join-requests/{$request->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/join-requests/{$request->id}/cancel")->assertStatus(409);
        $this->assertSame(1, $member->payments()->where('type', 'refund')->count());
    }

    public function test_one_pending_request_per_service_and_offer_required(): void
    {
        $offer = $this->offer();
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'months' => 1, 'method' => 'om', 'phone' => '0758421121'])
            ->assertStatus(422)->assertJsonPath('errors.offerId.0', 'Choisis une offre pour ce service.');

        $this->payFor($offer);
        $this->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $offer->id, 'months' => 1, 'method' => 'om', 'phone' => '0758421121'])
            ->assertStatus(422)->assertJsonPath('errors.offerId.0', 'Tu as déjà une demande en attente pour ce service.');
    }

    public function test_request_and_payment_in_progress_hold_the_last_seat(): void
    {
        $offer = $this->offer('netflix', ['seats' => 1]);
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $offer->id, 'months' => 1, 'method' => 'om', 'phone' => '0758421121'])->assertCreated();

        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'offerId' => $offer->id, 'months' => 1, 'method' => 'om', 'phone' => '0758421121'])
            ->assertStatus(422)->assertJsonPath('errors.offerId.0', 'Cette offre vient d’être complétée. Choisis-en une autre.');
    }

    public function test_spotify_host_sends_a_checked_invite_link_to_each_member(): void
    {
        $offer = $this->offer('spotify', ['plan' => 'famille', 'plan_label' => 'Famille · 6 comptes', 'access_mode' => 'family', 'access_email' => null, 'access_password' => null, 'price' => 1500]);
        $member = User::factory()->create();
        $this->actingAs($member);
        $request = $this->payFor($offer);

        $this->asHost()->postJson("/api/v1/host/requests/{$request->id}/accept")->assertOk()->assertJsonPath('data.invite', 'link');
        $sub = $member->subscriptions()->sole();
        $this->assertSame('pending', $sub->status->value);
        $memberId = $offer->members()->value('id');

        // Lien obligatoire, et forcément de Spotify (pas de lien piégé).
        $this->postJson("/api/v1/host/offers/{$offer->id}/members/{$memberId}/invite")->assertStatus(422)->assertJsonValidationErrors('link');
        foreach (['https://spotify.com.evil.io/join/x', 'http://www.spotify.com/family/join/x', 'https://bit.ly/abc'] as $bad) {
            $this->postJson("/api/v1/host/offers/{$offer->id}/members/{$memberId}/invite", ['link' => $bad])->assertStatus(422);
        }
        $link = 'https://www.spotify.com/ci-fr/family/join/invite/AbC123/';
        $this->postJson("/api/v1/host/offers/{$offer->id}/members/{$memberId}/invite", ['link' => $link])->assertOk()
            ->assertJsonPath('data.members.0.invitePending', false);

        $this->assertSame('active', $sub->fresh()->status->value);
        $this->actingAs($member)->getJson("/api/v1/subscriptions/{$sub->id}")->assertOk()
            ->assertJsonPath('data.invite.type', 'link')->assertJsonPath('data.invite.link', $link);

        // Un autre hôte ne peut pas inviter les membres de cette offre.
        $this->actingAs(User::factory()->create())->postJson("/api/v1/host/offers/{$offer->id}/members/{$memberId}/invite", ['link' => $link])->assertNotFound();
    }

    public function test_member_reports_broken_link_host_resends_and_reminder_before_expiry(): void
    {
        $offer = $this->offer('spotify', ['plan' => 'famille', 'plan_label' => 'Famille · 6 comptes', 'access_mode' => 'family', 'access_email' => null, 'access_password' => null, 'price' => 1500]);
        $member = User::factory()->create();
        $this->actingAs($member);
        $request = $this->payFor($offer);
        $this->asHost()->postJson("/api/v1/host/requests/{$request->id}/accept")->assertOk();
        $memberId = $offer->members()->value('id');
        $this->postJson("/api/v1/host/offers/{$offer->id}/members/{$memberId}/invite", ['link' => 'https://www.spotify.com/family/join/invite/A1/'])->assertOk();
        $sub = $member->subscriptions()->sole();

        // Le membre signale un lien cassé : l'hôte est prévenu, une seule fois par heure.
        $this->actingAs($member)->postJson("/api/v1/subscriptions/{$sub->id}/invite/broken")->assertOk()->assertJsonPath('data.invite.problemAt', fn ($v) => $v !== null);
        $this->postJson("/api/v1/subscriptions/{$sub->id}/invite/broken")->assertStatus(429);
        $this->assertSame(1, $this->host->notifications()->where('data->title', 'like', '%le lien ne marche plus%')->count());
        $this->asHost()->getJson('/api/v1/host')->assertJsonPath('data.offers.0.members.0.inviteProblemAt', fn ($v) => $v !== null);

        // L'hôte renvoie un lien : signalement effacé, le membre a le nouveau lien.
        $this->postJson("/api/v1/host/offers/{$offer->id}/members/{$memberId}/invite", ['link' => 'https://www.spotify.com/family/join/invite/B2/'])->assertOk();
        $this->actingAs($member)->getJson("/api/v1/subscriptions/{$sub->id}")
            ->assertJsonPath('data.invite.link', 'https://www.spotify.com/family/join/invite/B2/')->assertJsonPath('data.invite.problemAt', null);

        // 5 jours sans « J'ai rejoint » : un rappel (et un seul).
        $this->travel(5)->days();
        $this->assertSame(1, app(InviteReminder::class)->run());
        $this->assertSame(0, app(InviteReminder::class)->run());

        $this->actingAs($member)->postJson("/api/v1/subscriptions/{$sub->id}/invite/joined")->assertOk()->assertJsonPath('data.invite.joinedAt', fn ($v) => $v !== null);
        $this->actingAs(User::factory()->create())->postJson("/api/v1/subscriptions/{$sub->id}/invite/joined")->assertNotFound();
    }

    public function test_apple_music_member_gives_apple_id_email_seen_by_host_only_after_acceptance(): void
    {
        $offer = $this->offer('apple-music', ['plan' => 'famille', 'plan_label' => 'Famille · 6 comptes', 'access_mode' => 'family', 'access_email' => null, 'access_password' => null, 'price' => 1500]);
        $member = User::factory()->create();
        $this->actingAs($member);
        $body = ['serviceId' => 'apple-music', 'offerId' => $offer->id, 'months' => 1, 'method' => 'wave', 'phone' => '0758421121'];
        $this->postJson('/api/v1/payments', $body)->assertStatus(422)->assertJsonValidationErrors('inviteEmail');
        $ref = $this->postJson('/api/v1/payments', $body + ['inviteEmail' => ' Aya.Kone@iCloud.com '])->assertCreated()->json('data.ref');
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'succeeded');
        $request = JoinRequest::latest('id')->firstOrFail();

        // Avant acceptation : l'hôte ne voit pas l'e-mail.
        $this->assertStringNotContainsString('icloud', strtolower($this->asHost()->getJson('/api/v1/host')->getContent()));
        $this->postJson("/api/v1/host/requests/{$request->id}/accept")->assertOk()->assertJsonPath('data.members.0.inviteEmail', 'aya.kone@icloud.com');

        $memberId = $offer->members()->value('id');
        $this->postJson("/api/v1/host/offers/{$offer->id}/members/{$memberId}/invite", ['link' => 'https://x.y'])->assertStatus(422);
        $this->postJson("/api/v1/host/offers/{$offer->id}/members/{$memberId}/invite")->assertOk();
        $this->assertSame('active', $member->subscriptions()->sole()->status->value);
        $this->assertNotSame('aya.kone@icloud.com', DB::table('payments')->where('reference', $ref)->value('invite_email'), 'chiffré en base');
    }

    public function test_renewal_needs_no_approval_and_uses_current_price(): void
    {
        $offer = $this->offer();
        $member = User::factory()->create();
        $this->actingAs($member);
        $request = $this->payFor($offer);
        $this->asHost()->postJson("/api/v1/host/requests/{$request->id}/accept")->assertOk();
        $end = $member->subscriptions()->sole()->ends_at;

        $offer->update(['price' => 2000]);
        $this->actingAs($member);
        $ref = $this->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'months' => 1, 'method' => 'om', 'phone' => '0758421121'])->assertCreated()->json('data.ref');
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.amount', 2000)->assertJsonPath('data.status', 'succeeded');

        $this->assertTrue($member->subscriptions()->sole()->ends_at->equalTo($end->copy()->addMonth()));
        $this->assertSame(1, JoinRequest::count());
    }

    public function test_host_cannot_accept_someone_elses_request(): void
    {
        $offer = $this->offer();
        $this->actingAs(User::factory()->create());
        $request = $this->payFor($offer);

        $this->actingAs(User::factory()->create())->postJson("/api/v1/host/requests/{$request->id}/accept")->assertNotFound();
    }

    public function test_removal_counts_in_reliability(): void
    {
        $offer = $this->offer();
        $member = User::factory()->create();
        $this->actingAs($member);
        $request = $this->payFor($offer);
        $this->asHost()->postJson("/api/v1/host/requests/{$request->id}/accept")->assertOk();

        $this->deleteJson("/api/v1/host/offers/{$offer->id}/members/".$offer->members()->first()->id)->assertOk();
        $this->assertSame(1, $member->fresh()->removals_count);
    }

    public function test_validation_is_french_and_withdrawal_checks_balance(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/payments', ['serviceId' => 'netflix', 'months' => 2, 'method' => 'om', 'phone' => '07'])
            ->assertStatus(422)->assertJsonPath('errors.phone.0', 'Le numéro doit avoir 10 chiffres.')->assertJsonPath('errors.months.0', 'La durée est invalide.');

        $this->host->forceFill(['balance' => 10000])->save();
        $this->asHost()->postJson('/api/v1/host/withdrawals', ['amount' => 20000])->assertStatus(422);
        $this->postJson('/api/v1/host/withdrawals', ['amount' => 6000])->assertOk()->assertJsonPath('host.balance', 4000);
    }

    public function test_music_needs_no_offer_choice_but_host_still_approves(): void
    {
        $family = ['plan' => 'famille', 'plan_label' => 'Famille · 6 comptes', 'access_mode' => 'family', 'access_email' => null, 'access_password' => null];
        $this->offer('spotify', $family + ['price' => 1600]);
        $cheapest = $this->offer('spotify', $family + ['price' => 1400]);
        $this->assertFalse(collect($this->getJson('/api/v1/services')->json('data'))->firstWhere('id', 'spotify')['chooseOffer']);
        $this->assertTrue(collect($this->getJson('/api/v1/services')->json('data'))->firstWhere('id', 'netflix')['chooseOffer']);

        $member = User::factory()->create();
        $this->actingAs($member);
        $ref = $this->postJson('/api/v1/payments', ['serviceId' => 'spotify', 'months' => 1, 'method' => 'om', 'phone' => '0758421121'])
            ->assertCreated()->assertJsonPath('data.amount', 1400)->json('data.ref');
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.joinStatus', 'pending');

        $request = JoinRequest::sole();
        $this->assertSame($cheapest->id, $request->host_offer_id);
        $this->assertSame(0, $member->subscriptions()->count()); // l'hôte doit encore accepter
    }
}
