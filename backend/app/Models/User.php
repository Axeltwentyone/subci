<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PayMethod;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'first_name', 'last_name', 'phone', 'email', 'password', 'payout_method', 'payout_phone', 'last_pay_method', 'settings'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const DEFAULT_SETTINGS = [
        'notif_due' => true,
        'notif_seats' => true,
        'notif_promo' => false,
        'biometric' => true,
        'hide_access' => 'always',
        'data_saver' => 'auto',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->referral_code ??= static::makeReferralCode($user->name);
            $user->settings ??= self::DEFAULT_SETTINGS;
        });
    }

    /** Nom complet tenu à jour à partir du prénom et du nom. */
    public function setNames(string $first, string $last): void
    {
        $this->first_name = $first;
        $this->last_name = $last;
        $this->name = trim("{$first} {$last}");
        // Code attribué avant qu'on connaisse le prénom : on le personnalise.
        if (str_starts_with((string) $this->referral_code, 'SUB-')) {
            $this->referral_code = static::makeReferralCode($first);
        }
    }

    public static function makeReferralCode(?string $name): string
    {
        $prefix = Str::upper(Str::substr(Str::ascii($name ?: 'SUB'), 0, 3));
        do {
            $code = $prefix.'-'.Str::upper(Str::random(3));
        } while (static::where('referral_code', $code)->exists());

        return $code;
    }

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
            'balance' => 'integer',
            'removals_count' => 'integer',
            'suspended_at' => 'datetime',
            'payout_changed_at' => 'datetime',
            'payout_method' => PayMethod::class,
            'last_pay_method' => PayMethod::class,
        ];
    }

    /** « Aya K. » — ce que voient les autres utilisateurs. */
    public function shortName(): string
    {
        $first = $this->first_name ?? Str::before((string) $this->name, ' ');
        $initial = $this->last_name ? ' '.mb_strtoupper(mb_substr($this->last_name, 0, 1)).'.' : '';

        return trim(($first ?: 'Membre').$initial);
    }

    /** Fiabilité montrée à un hôte avant d'accepter une demande. */
    public function reliability(): array
    {
        return [
            'memberSince' => $this->created_at?->toIso8601String(),
            'paidCount' => $this->payments()->where('type', PaymentType::Subscription)->where('status', PaymentStatus::Succeeded)->whereNull('refunded_at')->count(),
            'removalsCount' => $this->removals_count,
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(JoinRequest::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function hostOffers(): HasMany
    {
        return $this->hasMany(HostOffer::class);
    }
}
