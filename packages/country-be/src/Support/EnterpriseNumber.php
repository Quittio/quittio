<?php

declare(strict_types=1);

namespace Quittio\Country\BE\Support;

/**
 * The Belgian enterprise number (ondernemingsnummer, numéro d'entreprise) of the Crossroads Bank
 * for Enterprises: ten digits, the last two 97 minus the first eight modulo 97. The VAT number is
 * "BE" and the enterprise number; Peppol uses it with scheme 0208.
 */
final class EnterpriseNumber
{
    /** Electronic address scheme of the enterprise number. */
    public const string SCHEME = '0208';

    /**
     * The ten digits of a number such as "0123.456.749", "BE 0123 456 749" or "123456749", or null.
     */
    public static function normalize(string $value): ?string
    {
        $digits = (string) preg_replace('/\D/', '', preg_replace('/^\s*BE/i', '', $value) ?? '');
        if (strlen($digits) === 9) {
            $digits = '0'.$digits;
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    /**
     * Whether the value is an enterprise number with valid check digits.
     */
    public static function isValid(string $value): bool
    {
        $digits = self::normalize($value);

        return $digits !== null && in_array($digits[0], ['0', '1'], true)
            && (int) substr($digits, 8, 2) === 97 - (int) substr($digits, 0, 8) % 97;
    }

    /**
     * The enterprise number of a Belgian VAT number ("BE0123456749"), or null for another value.
     */
    public static function fromVatId(?string $vatId): ?string
    {
        if ($vatId === null || ! str_starts_with(strtoupper(ltrim($vatId)), 'BE')) {
            return null;
        }
        $digits = self::normalize($vatId);

        return $digits !== null && self::isValid($digits) ? $digits : null;
    }

    /**
     * The number in the usual notation "0123.456.749".
     */
    public static function format(string $value): string
    {
        $digits = self::normalize($value) ?? $value;

        return substr($digits, 0, 4).'.'.substr($digits, 4, 3).'.'.substr($digits, 7, 3);
    }
}
