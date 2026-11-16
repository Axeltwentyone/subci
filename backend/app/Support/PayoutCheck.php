<?php

namespace App\Support;

use App\Enums\PayMethod;

/**
 * Un versement peut-il partir par Jèko (transfert mobile money) vers ce numéro ?
 * Jèko envoie vers Wave, Orange Money, MTN, Moov et Djamo, à un numéro ivoirien (+225).
 * Préfixes des opérateurs : 07 Orange, 05 MTN, 01 Moov ; Wave et Djamo acceptent tous les mobiles.
 */
class PayoutCheck
{
    private const PREFIX = ['om' => '07', 'mtn' => '05', 'moov' => '01'];

    /** @return array{ok: bool, issues: list<string>, method: string, phone: ?string, defaulted: bool} */
    public static function check(?PayMethod $method, ?string $phone): array
    {
        $defaulted = $method === null;
        $method ??= PayMethod::Wave;
        $issues = [];

        if ($method === PayMethod::Card) {
            $issues[] = 'Carte bancaire : Jèko ne verse pas sur une carte.';
        }
        if (! $phone) {
            $issues[] = 'Aucun numéro de retrait.';
        } elseif (! preg_match('/^0[157]\d{8}$/', $phone)) {
            $issues[] = "Numéro {$phone} : pas un mobile ivoirien (01, 05 ou 07 + 8 chiffres).";
        } elseif (isset(self::PREFIX[$method->value]) && ! str_starts_with($phone, self::PREFIX[$method->value])) {
            $issues[] = $method->label().' attend un numéro en '.self::PREFIX[$method->value].', pas en '.substr($phone, 0, 2).'.';
        }

        return ['ok' => ! $issues, 'issues' => $issues, 'method' => $method->value, 'phone' => $phone, 'defaulted' => $defaulted];
    }
}
