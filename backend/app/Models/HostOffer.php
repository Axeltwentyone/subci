<?php

namespace App\Models;

use App\Enums\AccessMode;
use App\Enums\OfferStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'service_id', 'plan_label', 'seats', 'price', 'access_mode', 'access_email', 'access_password',
    'proof_path', 'status', 'approved_at',
])]
#[Hidden(['access_email', 'access_password', 'proof_path'])]
class HostOffer extends Model
{
    /** Frais Sub.ci prélevés sur les gains de l'hôte. */
    public const FEE = 0.10;

    protected function casts(): array
    {
        return [
            'access_mode' => AccessMode::class,
            'status' => OfferStatus::class,
            'access_email' => 'encrypted',
            'access_password' => 'encrypted',
            'approved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(OfferMember::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Offres visibles dans le catalogue : preuve validée et partage actif. */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', OfferStatus::Live);
    }

    /** Places tenues par un paiement en cours (nouvel arrivant, demande non expirée). */
    public function scopeWithReservations(Builder $query): void
    {
        $query->withCount(['members', 'payments as reserved_count' => fn (Builder $q) => $q
            ->where('status', PaymentStatus::Pending)
            ->whereNull('subscription_id')
            ->where('expires_at', '>', now()),
        ]);
    }

    /** Places libres (nécessite withReservations). */
    public function freeSeats(): int
    {
        return max(0, $this->seats - (int) $this->members_count - (int) $this->reserved_count);
    }

    /** Gain net mensuel = places occupées × prix − 10 %. */
    public function monthlyNet(?int $occupied = null): int
    {
        $occupied ??= $this->members()->count();

        return (int) round($this->price * $occupied * (1 - self::FEE));
    }
}
