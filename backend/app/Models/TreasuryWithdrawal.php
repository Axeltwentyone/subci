<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Retrait de bénéfices Sub.ci : compté seulement une fois confirmé ; jamais supprimé. */
#[Fillable(['admin_id', 'amount', 'fee', 'net', 'destination', 'reference', 'status', 'withdrawn_at', 'decided_at', 'note', 'jeko_transaction_id'])]
class TreasuryWithdrawal extends Model
{
    public const STATUSES = ['pending', 'confirmed', 'failed'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee' => 'integer',
            'net' => 'integer',
            'withdrawn_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
