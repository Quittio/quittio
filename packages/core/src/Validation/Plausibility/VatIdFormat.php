<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Plausibility;

/**
 * Format of VAT identification numbers by country prefix (structure only, no check digits).
 */
final class VatIdFormat
{
    /** @var array<string, string> */
    private const array PATTERNS = [
        'AT' => 'U\d{8}',
        'BE' => '[01]\d{9}',
        'BG' => '\d{9,10}',
        'CH' => 'E\d{9}(MWST|TVA|IVA)?',
        'CY' => '\d{8}[A-Z]',
        'CZ' => '\d{8,10}',
        'DE' => '\d{9}',
        'DK' => '\d{8}',
        'EE' => '\d{9}',
        'EL' => '\d{9}',
        'ES' => '[A-Z0-9]\d{7}[A-Z0-9]',
        'FI' => '\d{8}',
        'FR' => '[A-HJ-NP-Z0-9]{2}\d{9}',
        'GB' => '(\d{9}|\d{12}|GD\d{3}|HA\d{3})',
        'HR' => '\d{11}',
        'HU' => '\d{8}',
        'IE' => '(\d{7}[A-W][A-I]?|\d[A-Z+*]\d{5}[A-W])',
        'IT' => '\d{11}',
        'LT' => '(\d{9}|\d{12})',
        'LU' => '\d{8}',
        'LV' => '\d{11}',
        'MT' => '\d{8}',
        'NL' => '\d{9}B\d{2}',
        'NO' => '\d{9}(MVA)?',
        'PL' => '\d{10}',
        'PT' => '\d{9}',
        'RO' => '\d{2,10}',
        'SE' => '\d{10}01',
        'SI' => '\d{8}',
        'SK' => '\d{10}',
        'XI' => '(\d{9}|\d{12}|GD\d{3}|HA\d{3})',
    ];

    /**
     * Small business identification number of the cross-border small business scheme (Article
     * 284 (3) VAT Directive): the country code, then the number with the suffix "EX", e.g.
     * DE123456789EX or PL1234567890-EX. It is no VAT identifier; its format apart from the
     * suffix is up to each member state.
     */
    public static function isSmallBusinessId(string $value): bool
    {
        $value = strtoupper(preg_replace('/[\s.\-]/', '', $value) ?? $value);

        return preg_match('/^[A-Z]{2}[A-Z0-9]*\d[A-Z0-9]*EX$/', $value) === 1 && self::isValid($value) !== true;
    }

    /**
     * Returns null if the country prefix is unknown (no statement possible).
     */
    public static function isValid(string $vatId): ?bool
    {
        $vatId = strtoupper(preg_replace('/[\s.\-]/', '', $vatId) ?? $vatId);
        $prefix = substr($vatId, 0, 2);
        $pattern = self::PATTERNS[$prefix] ?? null;
        if ($pattern === null) {
            return null;
        }

        return preg_match('/^'.$prefix.$pattern.'$/', $vatId) === 1;
    }
}
