<?php

declare(strict_types=1);

namespace Quittio\Country\FR\Support;

/**
 * SIREN (nine digits) and SIRET (fourteen digits) of the French business register, both with a
 * Luhn check digit; SIRET numbers of La Poste (SIREN 356000000) use a sum of digits instead.
 */
final class Siren
{
    /** SIREN of La Poste, whose SIRET numbers have a check of their own. */
    private const string LA_POSTE = '356000000';

    public static function isSiren(string $value): bool
    {
        $digits = self::digits($value);

        return strlen($digits) === 9 && self::luhn($digits);
    }

    public static function isSiret(string $value): bool
    {
        $digits = self::digits($value);
        if (strlen($digits) !== 14) {
            return false;
        }
        if (str_starts_with($digits, self::LA_POSTE)) {
            return array_sum(str_split($digits)) % 5 === 0;
        }

        return self::luhn($digits) && self::luhn(substr($digits, 0, 9));
    }

    /**
     * The SIREN of a SIREN, a SIRET or a French VAT number ("FR40303265045"), or null.
     */
    public static function of(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = self::digits($value);
        $candidate = match (true) {
            str_starts_with(strtoupper(ltrim($value)), 'FR') && strlen($digits) === 11 => substr($digits, 2),
            strlen($digits) === 14 => substr($digits, 0, 9),
            default => $digits,
        };

        return self::isSiren($candidate) ? $candidate : null;
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        foreach (str_split(strrev($digits)) as $index => $digit) {
            $value = (int) $digit * ($index % 2 === 1 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return $sum % 10 === 0;
    }
}
