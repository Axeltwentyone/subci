<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PayMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'user_id', 'service_id', 'subscription_id', 'host_offer_id', 'source_payment_id', 'type', 'status', 'reference', 'label', 'amount', 'gross', 'months',
    'method', 'phone', 'invite_email', 'provider_reference', 'checkout_url', 'return_url', 'period_start', 'period_end', 'expires_at', 'available_at', 'held_at',
    'confirmed_at', 'refunded_at',
])]
#[Hidden(['invite_email'])]
class Payment extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            $payment->reference ??= static::makeReference();
        });
    }

    /** SUB-8F2K-19 */
    public static function makeReference(): string
    {
        do {
            $ref = 'SUB-'.Str::upper(Str::random(4)).'-'.random_int(10, 99);
        } while (static::where('reference', $ref)->exists());

        return $ref;
    }

    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'status' => PaymentStatus::class,
            'method' => PayMethod::class,
            'amount' => 'integer',
            'gross' => 'integer',
            'invite_email' => 'encrypted',
            'available_at' => 'datetime',
            'held_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * Historique : paiements aboutis + remboursements / retraits en cours.
     * Les gains d'hôte en séquestre n'y figurent qu'une fois versés au solde
     * (ils sont résumés dans « à venir » du portefeuille).
     */
    public function scopeVisibleInHistory(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('status', PaymentStatus::Succeeded)
            ->orWhere(fn (Builder $q) => $q->where('status', PaymentStatus::Pending)->whereIn('type', [PaymentType::Refund, PaymentType::Withdrawal])));
    }

    /** Gains d'hôte encore en séquestre (pas encore dans le solde retirable). */
    public function scopeEscrowed(Builder $query): void
    {
        $query->where('type', PaymentType::Earning)->where('status', PaymentStatus::Pending);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'source_payment_id');
    }

    public function references(): HasMany
    {
        return $this->hasMany(PaymentReference::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function hostOffer(): BelongsTo
    {
        return $this->belongsTo(HostOffer::class);
    }

    public function joinRequest(): HasOne
    {
        return $this->hasOne(JoinRequest::class);
    }
}
