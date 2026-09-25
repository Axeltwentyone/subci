<?php

namespace App\Enums;

enum Device: string
{
    case Phone = 'phone';
    case Tablet = 'tablet';
    case Computer = 'computer';
    case Tv = 'tv';
}
