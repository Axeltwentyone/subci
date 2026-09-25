<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une demande envoyée à la passerelle pour un paiement (la première et chaque relance). */
#[Fillable(['payment_id', 'reference', 'status', 'refund_payment_id'])]
class PaymentReference extends Model
{
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
