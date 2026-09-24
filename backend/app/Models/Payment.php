<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PayMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'user_id', 'service_id', 'subscription_id', 'host_offer_id', 'type', 'status', 'reference', 'label', 'amount', 'months',
    'method', 'phone', 'provider_reference', 'period_start', 'period_end', 'expires_at', 'confirmed_at',
])]
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
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
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

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function hostOffer(): BelongsTo
    {
        return $this->belongsTo(HostOffer::class);
    }
}
