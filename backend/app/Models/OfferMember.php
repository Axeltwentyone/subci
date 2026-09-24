<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['host_offer_id', 'user_id', 'name', 'color', 'invite_pending', 'joined_at'])]
class OfferMember extends Model
{
    protected function casts(): array
    {
        return [
            'invite_pending' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(HostOffer::class, 'host_offer_id');
    }
}
