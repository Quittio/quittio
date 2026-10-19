<?php

declare(strict_types=1);

namespace Quittio\Country\CH\QrBill;

/**
 * The QR reference of the Swiss QR bill: 27 digits, the last a check digit after modulo 10
 * recursive (Swiss Implementation Guidelines QR-bill, annex B). It goes with a QR-IBAN only.
 */
final class QrReference
{
    /** The carry table of modulo 10 recursive. */
    private const array TABLE = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5];

    /**
     * Whether $reference (spaces allowed) is a QR reference with a correct check digit.
     */
    public static function isValid(string $reference): bool
    {
        $digits = str_replace(' ', '', $reference);

        return preg_match('/^\d{27}$/', $digits) === 1 && self::checkDigit(substr($digits, 0, 26)) === $digits[26];
    }

    /**
     * The QR reference of the digits of $value, e.g. of an invoice number "RE-2026-0042": the
     * digits padded with zeros to 26 and the check digit; null without digits or with more than 26.
     */
    public static function fromDigitsOf(string $value): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $value);
        if ($digits === '' || strlen($digits) > 26) {
            return null;
        }
        $base = str_pad($digits, 26, '0', STR_PAD_LEFT);

        return $base.self::checkDigit($base);
    }

    /**
     * The check digit of a string of digits after modulo 10 recursive.
     */
    public static function checkDigit(string $digits): string
    {
        $carry = 0;
        foreach (str_split($digits) as $digit) {
            $carry = self::TABLE[($carry + (int) $digit) % 10];
        }

        return (string) ((10 - $carry) % 10);
    }

    /**
     * The reference in blocks for printing: 2 digits, then blocks of 5.
     */
    public static function format(string $reference): string
    {
        $digits = str_replace(' ', '', $reference);

        return substr($digits, 0, 2).' '.implode(' ', str_split(substr($digits, 2), 5));
    }
}
