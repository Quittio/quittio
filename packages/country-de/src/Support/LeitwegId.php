<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Support;

/**
 * Leitweg-ID of public buyers in Germany (BT-10 for XRechnung):
 * coarse address (2–12 digits), optional fine address (up to 30 characters) and
 * check digits according to ISO 7064 Mod 97-10.
 */
final class LeitwegId
{
    private const string PATTERN = '/^(\d{2,12})(?:-([A-Za-z0-9]{1,30}))?-(\d{2})$/';

    /**
     * Whether the value has the shape of a Leitweg-ID (coarse address, optional fine address,
     * two check digits, separated by dashes), whatever its check digits.
     */
    public static function looksLike(?string $value): bool
    {
        return $value !== null && preg_match(self::PATTERN, trim($value)) === 1;
    }

    /**
     * Whether the value is a Leitweg-ID with correct check digits.
     */
    public static function isValid(?string $value): bool
    {
        if ($value === null || preg_match(self::PATTERN, trim($value), $m) !== 1) {
            return false;
        }

        return self::checkDigits($m[1].$m[2]) === $m[3];
    }

    /**
     * The two check digits (ISO 7064 MOD 97-10) of a Leitweg-ID, computed over the coarse and the
     * fine address concatenated without the dash, e.g. checkDigits('04011000'.'1234512345').
     */
    public static function checkDigits(string $address): string
    {
        $digits = '';
        foreach (str_split(strtoupper($address).'00') as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return sprintf('%02d', 98 - $remainder);
    }
}
