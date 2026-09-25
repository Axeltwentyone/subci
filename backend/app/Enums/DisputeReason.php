<?php

namespace App\Enums;

enum DisputeReason: string
{
    case NoAccess = 'no_access';
    case WrongPassword = 'wrong_password';
    case Removed = 'removed';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NoAccess => 'Pas d’accès',
            self::WrongPassword => 'Mot de passe changé',
            self::Removed => 'Retiré du compte',
            self::Other => 'Autre souci',
        };
    }
}
