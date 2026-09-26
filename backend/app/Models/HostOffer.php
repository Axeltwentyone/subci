<?php

namespace App\Models;

use App\Enums\AccessMode;
use App\Enums\JoinStatus;
use App\Enums\OfferStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'service_id', 'plan', 'plan_label', 'devices', 'quality', 'seats', 'price', 'access_mode', 'access_email', 'access_password',
    'proof_path', 'status', 'approved_at', 'rejection_reason',
])]
#[Hidden(['access_email', 'access_password', 'proof_path'])]
class HostOffer extends Model
{
    /** Frais Sub.ci prélevés sur les gains de l'hôte. */
    /** Commission Sub.ci sur le prix de l'offre (l'hôte garde 95 %). */
    public const FEE = 0.05;

    protected function casts(): array
    {
        return [
            'access_mode' => AccessMode::class,
            'status' => OfferStatus::class,
            'access_email' => 'encrypted',
            'access_password' => 'encrypted',
            'approved_at' => 'datetime',
            'devices' => 'array',
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

    /**
     * Places tenues sans être encore occupées :
     * - paiement en cours d'un nouvel arrivant (demande mobile money non expirée) ;
     * - demande payée en attente de la réponse de l'hôte.
     */
    public function scopeWithReservations(Builder $query): void
    {
        $query->withCount([
            'members',
            'payments as reserved_count' => fn (Builder $q) => $q
                ->where('status', PaymentStatus::Pending)
                ->whereNull('subscription_id')
                ->where('expires_at', '>', now()),
            'joinRequests as requested_count' => fn (Builder $q) => $q->where('status', JoinStatus::Pending),
        ]);
    }

    /** Places libres (nécessite withReservations). */
    public function freeSeats(): int
    {
        return max(0, $this->seats - (int) $this->members_count - (int) $this->reserved_count - (int) $this->requested_count);
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(JoinRequest::class);
    }

    /** Définition de la formule (config/plans.php). */
    public function planConfig(): array
    {
        return config("plans.{$this->service->slug}.{$this->plan}")
            // Formule inconnue : l'hôte garde sa place, le reste du service est partageable.
            ?? ['label' => $this->plan_label, 'max' => max($this->seats, $this->service->seats - 1), 'devices' => ['phone', 'tablet', 'computer', 'tv'], 'mode' => $this->access_mode->value, 'reco' => [500, 5000]];
    }

    /** Offre famille : « link » (lien d'invitation par membre) ou « email » (compte du membre), sinon null. */
    public function inviteType(): ?string
    {
        if ($this->access_mode !== AccessMode::Family) {
            return null;
        }

        return $this->planConfig()['invite'] ?? 'link';
    }

    /** Le lien d'invitation pointe bien vers le service (pas de lien piégé envoyé par un hôte). */
    public function acceptsInviteLink(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }

        return collect($this->planConfig()['invite_hosts'] ?? [])
            ->contains(fn (string $d) => $host === $d || str_ends_with($host, '.'.$d));
    }

    /** Gain net mensuel = places occupées × prix − commission. */
    public function monthlyNet(?int $occupied = null): int
    {
        $occupied ??= $this->members()->count();

        return (int) round($this->price * $occupied * (1 - self::FEE));
    }
}
