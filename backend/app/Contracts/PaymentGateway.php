<?php

namespace App\Contracts;

use App\Enums\PaymentStatus;
use App\Models\Payment;

/** Agrégateur mobile money (Orange Money, Wave, MTN, Moov, carte). */
interface PaymentGateway
{
    /**
     * Crée la demande de paiement chez l'opérateur.
     *
     * @return array{reference: string, url: ?string} référence opérateur et page où le client valide (le cas échéant)
     */
    public function request(Payment $payment): array;

    /** Statut côté opérateur d'une demande en attente. */
    public function status(Payment $payment): PaymentStatus;
}
