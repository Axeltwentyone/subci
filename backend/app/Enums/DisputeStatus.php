<?php

namespace App\Enums;

enum DisputeStatus: string
{
    /** Gains de l'hôte pour ce membre gelés. */
    case Open = 'open';
    /** Le membre dit que c'est réglé : gains libérés. */
    case Solved = 'solved';
    /** Décision admin : membre remboursé du temps non encore versé à l'hôte. */
    case Refunded = 'refunded';
    /** Décision admin : rien à reprocher à l'hôte, gains libérés. */
    case Rejected = 'rejected';
}
