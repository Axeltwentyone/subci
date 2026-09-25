<?php

namespace App\Support;

/**
 * Codes à usage unique basés sur le temps (RFC 6238) : Google Authenticator,
 * Microsoft Authenticator, 1Password… 6 chiffres, pas de 30 s, HMAC-SHA1.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($chunk) => self::ALPHABET[bindec(str_pad($chunk, 5, '0'))], str_split($bits, 5)));
    }

    public static function uri(string $secret, string $account, string $issuer = 'Sub.ci Admin'): string
    {
        return 'otpauth://totp/'.rawurlencode("{$issuer}:{$account}").'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30,
        ]);
    }

    public static function code(string $secret, int $step): string
    {
        $key = self::decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Pas de temps accepté (±1 pour l'horloge du téléphone), ou null.
     * Un pas déjà utilisé (≤ $lastStep) est refusé : un code intercepté ne sert pas deux fois.
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $now = null): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $current = intdiv($now ?? now()->getTimestamp(), 30);
        foreach ([0, -1, 1] as $drift) {
            $step = $current + $drift;
            if (($lastStep === null || $step > $lastStep) && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    private static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i !== false) {
                $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
            }
        }

        return implode('', array_map(fn ($byte) => chr(bindec($byte)), array_filter(str_split($bits, 8), fn ($b) => strlen($b) === 8)));
    }
}
