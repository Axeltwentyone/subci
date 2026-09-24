<?php

namespace App\Enums;

enum OfferStatus: string
{
    /** Preuve d'abonnement en cours de vérification (sous 1 h) */
    case Review = 'review';
    case Live = 'live';
    /** Plus de nouveaux membres ; les actuels gardent leur accès. */
    case Paused = 'paused';
    /** L'hôte arrête de partager : les membres gardent l'accès jusqu'à leur échéance. */
    case Closed = 'closed';

    /** Les places libres de l'offre sont-elles proposées dans le catalogue ? */
    public function isOpen(): bool
    {
        return $this === self::Review || $this === self::Live;
    }
}
