<?php

namespace App\Contracts;

use App\Enums\PaymentStatus;
use App\Models\Payment;

/** Agrégateur mobile money (Orange Money, Wave, MTN, Moov, carte). */
interface PaymentGateway
{
    /** Envoie la demande de paiement sur le téléphone du client ; renvoie la référence opérateur. */
    public function request(Payment $payment): string;

    /** Statut côté opérateur d'une demande en attente. */
    public function status(Payment $payment): PaymentStatus;
}
