<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Solde du compte marchand relevé à un instant (API Jèko ou saisie manuelle). */
#[Fillable(['admin_id', 'amount', 'source', 'note', 'recorded_at'])]
class MerchantBalance extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'recorded_at' => 'datetime'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
