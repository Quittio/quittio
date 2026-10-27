<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Plausibility;

/**
 * IBAN check digit validation (ISO 13616, mod 97-10) without extensions.
 */
final class Iban
{
    /**
     * Removes all whitespace and upper-cases the IBAN, e.g. "de02 1203 0000" becomes "DE021203000".
     */
    public static function normalize(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban) ?? $iban);
    }

    /**
     * Whether the IBAN, after normalize(), has the IBAN structure (country code, two check digits,
     * 11 to 30 alphanumeric characters) and correct check digits (mod 97 = 1). The country specific
     * length is not checked.
     */
    public static function isValid(string $iban): bool
    {
        $iban = self::normalize($iban);
        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        return self::mod97(substr($iban, 4).substr($iban, 0, 4)) === 1;
    }

    /**
     * Remainder of a number given as alphanumeric string (A = 10 … Z = 35) divided by 97.
     */
    public static function mod97(string $value): int
    {
        $remainder = 0;
        foreach (str_split(strtoupper($value)) as $char) {
            $digits = ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder;
    }
}
