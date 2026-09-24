<?php

namespace App\Models;

use App\Enums\PayMethod;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'service_id', 'host_offer_id', 'status', 'starts_at', 'ends_at', 'activates_at', 'auto_renew', 'reminded_j3_at', 'reminded_j1_at', 'pay_method',
    'profile_label', 'access_email', 'access_password', 'access_pin',
])]
#[Hidden(['access_email', 'access_password', 'access_pin'])]
class Subscription extends Model
{
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'pay_method' => PayMethod::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'activates_at' => 'datetime',
            'auto_renew' => 'boolean',
            'reminded_j3_at' => 'datetime',
            'reminded_j1_at' => 'datetime',
            // Coffre des accès chiffré au repos avec APP_KEY.
            'access_email' => 'encrypted',
            'access_password' => 'encrypted',
            'access_pin' => 'encrypted',
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

    public function hostOffer(): BelongsTo
    {
        return $this->belongsTo(HostOffer::class);
    }

    /** Statut affiché : « due » = actif et échéance à ≤ 5 jours. */
    public function displayStatus(): string
    {
        if ($this->status !== SubscriptionStatus::Active) {
            return $this->status->value;
        }

        return $this->daysLeft() <= 5 ? 'due' : 'active';
    }

    public function daysLeft(): int
    {
        return (int) ceil(now()->diffInSeconds($this->ends_at, false) / 86400);
    }
}
