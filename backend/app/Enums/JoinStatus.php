<?php

namespace App\Enums;

enum JoinStatus: string
{
    /** Payé, en attente de la réponse de l'hôte (24 h max). */
    case Pending = 'pending';
    case Accepted = 'accepted';
    /** Refusé par l'hôte → remboursé. */
    case Declined = 'declined';
    /** Pas de réponse sous 24 h → remboursé. */
    case Expired = 'expired';
    /** Annulé par le membre avant la réponse → remboursé. */
    case Cancelled = 'cancelled';
}
