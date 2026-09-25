<?php

namespace App\Models;

use App\Enums\JoinStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['host_offer_id', 'user_id', 'payment_id', 'status', 'expires_at', 'decided_at'])]
class JoinRequest extends Model
{
    /** Délai de réponse de l'hôte. */
    public const TTL_HOURS = 24;

    protected function casts(): array
    {
        return [
            'status' => JoinStatus::class,
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', JoinStatus::Pending);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(HostOffer::class, 'host_offer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
