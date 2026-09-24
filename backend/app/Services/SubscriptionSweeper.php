<?php

namespace App\Services;

use App\Enums\OfferStatus;
use App\Enums\SubscriptionStatus;
use App\Models\HostOffer;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** Active les accès prêts et expire les abonnements échus. */
class SubscriptionSweeper
{
    public function run(?User $user = null): int
    {
        $scope = fn (Builder $q) => $user ? $q->where('user_id', $user->id) : $q;
        $changed = 0;

        Subscription::query()->tap($scope)
            ->where('status', SubscriptionStatus::Pending)
            ->where('activates_at', '<=', now())
            ->with('service', 'user')
            ->each(function (Subscription $sub) use (&$changed) {
                $sub->update(['status' => SubscriptionStatus::Active]);
                $sub->user->notify(new AppNotification('ok', Str::before($sub->service->name, ' ').' est activé', 'Tes identifiants sont disponibles.', ['label' => 'Voir', 'to' => "/subs/{$sub->id}"]));
                $changed++;
            });

        $changed += Subscription::query()->tap($scope)
            ->where('status', SubscriptionStatus::Active)
            ->where('ends_at', '<', now())
            ->update(['status' => SubscriptionStatus::Expired]);

        return $changed;
    }

    /**
     * Validation des preuves. En production : modération manuelle (`offers:approve`).
     * En local, OFFERS_AUTO_APPROVE_AFTER=30 met l'offre en ligne au bout de 30 s.
     */
    public function approveOffers(?User $user = null): int
    {
        $after = config('services.offers.auto_approve_after');
        if ($after === null) {
            return 0;
        }

        return HostOffer::query()
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->where('status', OfferStatus::Review)
            ->where('created_at', '<=', now()->subSeconds($after))
            ->with('service', 'user')
            ->get()
            ->each(fn (HostOffer $offer) => self::approve($offer))
            ->count();
    }

    public static function approve(HostOffer $offer): void
    {
        $offer->update(['status' => OfferStatus::Live, 'approved_at' => now()]);
        $offer->user->notify(new AppNotification(
            'host', 'Ton offre est en ligne', Str::before($offer->service->name, ' ').' : tes places sont visibles dans le catalogue.',
            ['label' => 'Gérer', 'to' => "/host/offers/{$offer->id}"],
        ));
    }
}
