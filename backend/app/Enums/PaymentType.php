<?php

namespace App\Enums;

enum PaymentType: string
{
    /** Achat / renouvellement d'une place (débit membre) */
    case Subscription = 'subscription';
    /** Versement des gains d'une offre (crédit hôte) */
    case Earning = 'earning';
    /** Retrait du solde hôte vers le mobile money (débit hôte) */
    case Withdrawal = 'withdrawal';

    public function direction(): string
    {
        return $this === self::Earning ? 'in' : 'out';
    }
}
