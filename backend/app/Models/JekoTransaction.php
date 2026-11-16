<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne du relevé Jèko (montants en FCFA). */
#[Fillable(['jeko_id', 'type', 'status', 'method', 'amount', 'fee', 'counterpart', 'jeko_reference', 'executed_at', 'payment_id', 'treasury_withdrawal_id', 'ignored_reason'])]
class JekoTransaction extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'fee' => 'integer', 'executed_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(TreasuryWithdrawal::class, 'treasury_withdrawal_id');
    }

    public function isClassified(): bool
    {
        return $this->payment_id || $this->treasury_withdrawal_id || $this->ignored_reason;
    }
}
