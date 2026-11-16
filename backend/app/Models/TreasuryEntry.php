<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Charge ou ajustement manuel. Immuable : une erreur se corrige par une écriture inverse. */
#[Fillable(['admin_id', 'kind', 'amount', 'label', 'from_merchant', 'reverses_id', 'occurred_at'])]
class TreasuryEntry extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Écriture de trésorerie immuable.'));
        static::deleting(fn () => throw new LogicException('Écriture de trésorerie immuable.'));
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'from_merchant' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }
}
