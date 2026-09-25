<?php

namespace App\Models;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['subscription_id', 'user_id', 'host_offer_id', 'reason', 'message', 'status', 'resolution', 'resolved_by', 'resolved_at'])]
class Dispute extends Model
{
    protected function casts(): array
    {
        return [
            'reason' => DisputeReason::class,
            'status' => DisputeStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(HostOffer::class, 'host_offer_id');
    }
}
