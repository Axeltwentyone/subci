<?php

namespace App\Enums;

enum AccessMode: string
{
    /** Identifiants + profil partagés, chiffrés, visibles après paiement */
    case Credentials = 'credentials';
    /** Invitation famille (Spotify, YouTube) */
    case Family = 'family';
}
