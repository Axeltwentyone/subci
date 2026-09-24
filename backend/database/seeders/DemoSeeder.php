<?php

namespace Database\Seeders;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\HostOffer;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Database\Seeder;

/** Compte démo « Aya Koné » — mêmes données que le mode démo de la PWA. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $s = Service::pluck('id', 'slug');

        $aya = User::updateOrCreate(['phone' => '0758421121'], [
            'name' => 'Aya Koné',
            'first_name' => 'Aya',
            'last_name' => 'Koné',
            'payout_method' => 'wave',
            'payout_phone' => '0758421121',
            'last_pay_method' => 'om',
        ]);
        $aya->forceFill(['phone_verified_at' => now(), 'balance' => 13500, 'referral_code' => 'AYA-2K9'])->save();

        $aya->subscriptions()->delete();
        $aya->payments()->delete();
        $aya->hostOffers()->delete();
        $aya->notifications()->delete();

        $groups = $this->hostsWithOffers($s);

        $netflix = $aya->subscriptions()->create([
            'host_offer_id' => $groups['netflix']->id,
            'service_id' => $s['netflix'], 'status' => 'active', 'starts_at' => now()->subDays(27), 'ends_at' => now()->addDays(3)->subHour(),
            'auto_renew' => true, 'pay_method' => 'om', 'profile_label' => 'Profil 3 · « Aya »',
            'access_email' => 'aya.n3@sub.ci', 'access_password' => 'Nf8-kQ2z!pL', 'access_pin' => '4817',
        ]);
        $aya->subscriptions()->create([
            'host_offer_id' => $groups['spotify']->id,
            'service_id' => $s['spotify'], 'status' => 'active', 'starts_at' => now()->subDays(12), 'ends_at' => now()->addDays(18),
            'auto_renew' => true, 'pay_method' => 'om', 'profile_label' => 'Invitation famille acceptée',
            'access_email' => 'aya.kone@gmail.com', 'access_password' => '— ton propre mot de passe —',
        ]);
        $aya->subscriptions()->create([
            'host_offer_id' => $groups['chatgpt']->id,
            'service_id' => $s['chatgpt'], 'status' => 'pending', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'activates_at' => now()->addMinutes(10),
            'auto_renew' => false, 'pay_method' => 'wave', 'profile_label' => 'Espace de travail « Sub 7 »',
            'access_email' => 'aya.c7@sub.ci', 'access_password' => 'Gp7#vW3m@xR',
        ]);
        $aya->subscriptions()->create([
            'service_id' => $s['disney'], 'status' => 'expired', 'starts_at' => now()->subDays(70), 'ends_at' => now()->subDays(40),
            'auto_renew' => false, 'pay_method' => 'om', 'profile_label' => 'Profil 2', 'access_email' => 'aya.d2@sub.ci', 'access_password' => 'Dz4!mQ8r',
        ]);
        $aya->subscriptions()->create([
            'service_id' => $s['prime'], 'status' => 'expired', 'starts_at' => now()->subDays(95), 'ends_at' => now()->subDays(65),
            'auto_renew' => false, 'pay_method' => 'mtn', 'profile_label' => 'Profil 4', 'access_email' => 'aya.p4@sub.ci', 'access_password' => 'Pv2@nB6s',
        ]);

        $pay = fn (array $a, $at) => tap($aya->payments()->create($a + ['status' => PaymentStatus::Succeeded, 'confirmed_at' => $at]), function ($p) use ($at) {
            $p->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        });
        $pay(['type' => PaymentType::Subscription, 'service_id' => $s['netflix'], 'subscription_id' => $netflix->id, 'reference' => 'SUB-8F2K-19', 'label' => 'Netflix Premium · 3 mois', 'amount' => 7125, 'months' => 3, 'method' => 'om', 'phone' => '0758421121'], now()->subMinutes(45));
        $pay(['type' => PaymentType::Earning, 'service_id' => $s['netflix'], 'reference' => 'SUB-2HQ1-07', 'label' => 'Gains Netflix · 3 membres', 'amount' => 6750, 'method' => 'wave'], now()->subDays(5));
        $pay(['type' => PaymentType::Subscription, 'service_id' => $s['spotify'], 'reference' => 'SUB-7DL3-42', 'label' => 'Spotify Famille · 1 mois', 'amount' => 1500, 'months' => 1, 'method' => 'om', 'phone' => '0758421121'], now()->subDays(12));
        $pay(['type' => PaymentType::Subscription, 'service_id' => $s['chatgpt'], 'reference' => 'SUB-4MX9-11', 'label' => 'ChatGPT Plus · 1 mois', 'amount' => 5000, 'months' => 1, 'method' => 'wave', 'phone' => '0758421121'], now()->subDays(20));

        $colors = ['#FFB38F', '#9FD7BE', '#C9B8F2', '#F7D774', '#9CC7F2'];
        $o1 = $aya->hostOffers()->create(['service_id' => $s['netflix'], 'plan_label' => 'Premium · 4 écrans', 'seats' => 3, 'price' => 2500, 'access_mode' => 'credentials', 'status' => 'live', 'approved_at' => now()->subMonths(2), 'access_email' => 'aya.kone@gmail.com', 'access_password' => 'demo-host-pass']);
        foreach (['Koffi', 'Mariam', 'Yao'] as $i => $n) {
            $o1->members()->create(['name' => $n, 'color' => $colors[$i], 'joined_at' => now()->subDays(20 - $i)]);
        }
        $o2 = $aya->hostOffers()->create(['service_id' => $s['spotify'], 'plan_label' => 'Famille · 6 comptes', 'seats' => 5, 'price' => 1500, 'access_mode' => 'family', 'status' => 'live', 'approved_at' => now()->subMonth()]);
        foreach (['Awa', 'Ismaël', 'Fatou', 'Moussa'] as $i => $n) {
            $o2->members()->create(['name' => $n, 'color' => $colors[($i + 3) % 5], 'joined_at' => now()->subDays(10 - $i), 'invite_pending' => $n === 'Moussa']);
        }

        $notify = function (AppNotification $n, $at, bool $read) use ($aya) {
            $aya->notify($n);
            $aya->notifications()->latest()->first()->forceFill(['created_at' => $at, 'updated_at' => $at, 'read_at' => $read ? $at : null])->save();
        };
        $notify(new AppNotification('seat', 'Une place s’est libérée', 'YouTube Premium — réservée 30 min pour toi.', ['label' => 'Voir', 'to' => '/service/youtube']), now()->subDays(3), true);
        $notify(new AppNotification('due', 'Spotify expire dans 3 jours', 'Renouvelle pour garder ta place.', ['label' => 'Renouveler', 'to' => '/checkout/spotify']), now()->subDays(2), false);
        $notify(new AppNotification('pay', 'Paiement confirmé', '7 125 FCFA via Orange Money'), now()->subMinutes(45), true);
        $notify(new AppNotification('ok', 'Netflix est activé', 'Tes identifiants sont disponibles.', ['label' => 'Voir', 'to' => '/subs/'.$netflix->id]), now()->subMinutes(40), false);
    }

    /**
     * Hôtes de démo et leurs offres en ligne : ce sont elles qui alimentent
     * les places libres du catalogue. Aya est membre de trois de ces groupes.
     *
     * @return array<string, HostOffer> offre par service dont Aya est membre
     */
    private function hostsWithOffers($s): array
    {
        $colors = ['#FFB38F', '#9FD7BE', '#C9B8F2', '#F7D774', '#9CC7F2'];
        // [prénom, nom, téléphone, [[service, formule, places, prix, mode, membres…]]]
        $hosts = [
            ['Koffi', 'Yao', '0700000001', [
                ['netflix', 'Premium · 4 écrans', 3, 2500, 'credentials', ['Aya K.', 'Serge B.']],
                ['spotify-duo', 'Duo · 2 comptes', 1, 2200, 'family', ['Nadia O.']],
            ]],
            ['Mariam', 'Traoré', '0700000002', [
                ['spotify', 'Famille · 6 comptes', 5, 1500, 'family', ['Aya K.', 'Paul E.', 'Inès D.']],
                ['youtube', 'Famille · 6 comptes', 5, 1800, 'family', ['Karim S.', 'Léa M.', 'Hervé A.', 'Rokia C.', 'Didier Z.']],
            ]],
            ['Yao', 'Kouassi', '0700000003', [
                ['canal', 'Évasion · 3 écrans', 2, 3000, 'credentials', ['Brice N.']],
                ['canal-sport', 'Sport · 3 écrans', 2, 4000, 'credentials', ['Franck L.']],
            ]],
            ['Fatou', 'Diallo', '0700000004', [
                ['prime', 'Prime · 5 profils', 4, 1200, 'credentials', ['Olivier T.']],
                ['chatgpt', 'Team · 2 sièges', 2, 5000, 'credentials', ['Aya K.']],
            ]],
            ['Ismaël', 'Bamba', '0700000005', [
                ['disney', 'Premium · 4 écrans', 3, 2000, 'credentials', []],
            ]],
        ];

        $ayaGroups = [];
        foreach ($hosts as [$first, $last, $phone, $offers]) {
            $host = User::updateOrCreate(['phone' => $phone], ['first_name' => $first, 'last_name' => $last, 'name' => "{$first} {$last}", 'payout_method' => 'wave', 'payout_phone' => $phone]);
            $host->forceFill(['phone_verified_at' => now(), 'balance' => 0])->save();
            $host->hostOffers()->delete();
            $host->payments()->delete();
            $host->notifications()->delete();

            foreach ($offers as $i => [$slug, $plan, $seats, $price, $mode, $members]) {
                $offer = $host->hostOffers()->create([
                    'service_id' => $s[$slug], 'plan_label' => $plan, 'seats' => $seats, 'price' => $price, 'access_mode' => $mode,
                    'access_email' => $mode === 'credentials' ? strtolower($first).'.'.$slug.'@sub.ci' : null,
                    'access_password' => $mode === 'credentials' ? 'Demo-'.ucfirst($slug).'-'.$phone : null,
                    'status' => 'live', 'approved_at' => now()->subDays(30 + $i),
                ]);
                foreach ($members as $k => $name) {
                    $offer->members()->create(['name' => $name, 'color' => $colors[$k % 5], 'joined_at' => now()->subDays(20 - $k)]);
                    if ($name === 'Aya K.') {
                        $ayaGroups[$slug] = $offer;
                    }
                }
            }
        }

        return $ayaGroups;
    }
}
