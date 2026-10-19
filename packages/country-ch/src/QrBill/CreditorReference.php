<?php

declare(strict_types=1);

namespace Quittio\Country\CH\QrBill;

/**
 * The Creditor Reference of ISO 11649: "RF", two check digits (modulo 97-10) and up to 21 letters
 * and digits. It goes with an ordinary IBAN in the Swiss QR bill.
 */
final class CreditorReference
{
    /**
     * Whether $reference (spaces allowed, any case) is a Creditor Reference with correct check digits.
     */
    public static function isValid(string $reference): bool
    {
        $value = strtoupper(str_replace(' ', '', $reference));
        if (preg_match('/^RF\d{2}[A-Z0-9]{1,21}$/', $value) !== 1) {
            return false;
        }

        return self::mod97(substr($value, 4).substr($value, 0, 4)) === 1;
    }

    /**
     * The Creditor Reference of the letters and digits of $value, e.g. of an invoice number; null
     * without any or with more than 21.
     */
    public static function fromCharactersOf(string $value): ?string
    {
        $base = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
        if ($base === '' || strlen($base) > 21) {
            return null;
        }

        return 'RF'.str_pad((string) (98 - self::mod97($base.'RF00')), 2, '0', STR_PAD_LEFT).$base;
    }

    /**
     * The reference in blocks of four for printing.
     */
    public static function format(string $reference): string
    {
        return implode(' ', str_split(strtoupper(str_replace(' ', '', $reference)), 4));
    }

    /**
     * The remainder modulo 97 of the number that replaces every letter by two digits (A = 10).
     */
    private static function mod97(string $value): int
    {
        $remainder = 0;
        foreach (str_split($value) as $character) {
            $number = ctype_digit($character) ? $character : (string) (ord($character) - 55);
            foreach (str_split($number) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder;
    }
}
