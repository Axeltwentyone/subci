<?php

namespace App\Enums;

enum PayMethod: string
{
    case OrangeMoney = 'om';
    case Wave = 'wave';
    case Mtn = 'mtn';
    case Moov = 'moov';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::OrangeMoney => 'Orange Money',
            self::Wave => 'Wave',
            self::Mtn => 'MTN MoMo',
            self::Moov => 'Moov Money',
            self::Card => 'Carte bancaire',
        };
    }
}
