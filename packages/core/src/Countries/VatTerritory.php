<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * The EU VAT territory (Art. 5 to 7 of Directive 2006/112/EC) as far as it can be told from
 * a country code and a postcode.
 *
 * - The 27 member states with their ISO 3166-1 codes (Greece is "GR"; "EL" is only the VAT prefix).
 * - Northern Ireland belongs to it for goods only (Windsor Framework). It is recognised by the
 *   code "XI" and by United Kingdom addresses with a "BT" postcode.
 * - Monaco is treated as France and the Isle of Man as the United Kingdom (Art. 7). Outside the
 *   EU, Liechtenstein is treated as Switzerland: both form one area of application of the VAT
 *   (treaty of 28 November 1994).
 * - Territories excluded by Art. 6 are recognised by their postcode where it identifies them
 *   reliably: Büsingen am Hochrhein and Heligoland (DE, also not part of the German Inland under
 *   section 1 (2) UStG), the Canary Islands, Ceuta and Melilla (ES), Livigno and Campione d'Italia
 *   (IT), the Åland Islands (FI), Mount Athos (GR) and the French overseas territories (FR 97…, 98…
 *   except Monaco 980…). Excluded territories with own country codes (e.g. FO, GL, AX, IC) are
 *   outside anyway. Territories without a distinct postcode (e.g. the part of Cyprus not
 *   controlled by the government, the Italian waters of Lake Lugano) cannot be detected.
 */
final class VatTerritory
{
    /** @var list<string> */
    public const array EU_MEMBER_STATES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /** Northern Ireland: part of the EU VAT area for goods (Windsor Framework). */
    public const string NORTHERN_IRELAND = 'XI';

    /** @var array<string, string> countries that belong to the VAT territory of another country */
    private const array VAT_COUNTRY = ['MC' => 'FR', 'IM' => 'GB', 'EL' => 'GR', 'LI' => 'CH'];

    /**
     * Excluded territories per country: postcode pattern (digits only) => name.
     *
     * @var array<string, array<string, string>>
     */
    private const array EXCLUDED = [
        'DE' => ['/^78266$/' => 'Büsingen am Hochrhein', '/^27498$/' => 'Heligoland'],
        'ES' => ['/^3[58]\d{3}$/' => 'Canary Islands', '/^51\d{3}$/' => 'Ceuta', '/^52\d{3}$/' => 'Melilla'],
        'IT' => ['/^23041$/' => 'Livigno', '/^22061$/' => "Campione d'Italia"],
        'FI' => ['/^22\d{3}$/' => 'Åland Islands'],
        'GR' => ['/^63086$/' => 'Mount Athos'],
        'FR' => ['/^97\d{3}$/' => 'French overseas territory', '/^98[1-9]\d{2}$/' => 'French overseas territory'],
    ];

    /**
     * The country whose VAT territory the given country belongs to (MC → FR, IM → GB, EL → GR,
     * LI → CH).
     */
    public static function vatCountry(string $country): string
    {
        $country = strtoupper(trim($country));

        return self::VAT_COUNTRY[$country] ?? $country;
    }

    public static function isEuMemberState(string $country): bool
    {
        return in_array(self::vatCountry($country), self::EU_MEMBER_STATES, true);
    }

    /**
     * Name of the territory excluded from the EU VAT territory that the address lies in, or null.
     */
    public static function excludedTerritory(string $country, ?string $postcode): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $postcode) ?? '';
        if ($digits === '') {
            return null;
        }

        foreach (self::EXCLUDED[self::vatCountry($country)] ?? [] as $pattern => $name) {
            if (preg_match($pattern, $digits) === 1) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Whether an address lies in the EU VAT territory.
     *
     * @param  bool  $goods  Northern Ireland counts for supplies of goods only
     */
    public static function isEuVatTerritory(string $country, ?string $postcode = null, bool $goods = true): bool
    {
        return self::memberState($country, $postcode, $goods) !== null;
    }

    /**
     * The member state (or "XI" for Northern Ireland and goods) of an address in the EU VAT
     * territory, null outside of it.
     */
    public static function memberState(string $country, ?string $postcode = null, bool $goods = true): ?string
    {
        if (self::isNorthernIreland($country, $postcode)) {
            return $goods ? self::NORTHERN_IRELAND : null;
        }
        $country = self::vatCountry($country);
        if (! in_array($country, self::EU_MEMBER_STATES, true) || self::excludedTerritory($country, $postcode) !== null) {
            return null;
        }

        return $country;
    }

    /**
     * Whether two addresses lie in the same VAT territory (the same country and both inside or
     * both outside its excluded territories), e.g. FR and MC, but not DE and Büsingen.
     */
    public static function sameVatTerritory(string $country, ?string $postcode, string $otherCountry, ?string $otherPostcode): bool
    {
        return self::territoryKey($country, $postcode) === self::territoryKey($otherCountry, $otherPostcode);
    }

    /**
     * Northern Ireland: the code "XI" or a United Kingdom address with a "BT" postcode.
     */
    public static function isNorthernIreland(string $country, ?string $postcode = null): bool
    {
        $country = self::vatCountry($country);

        return $country === self::NORTHERN_IRELAND
            || ($country === 'GB' && preg_match('/^BT\d/', strtoupper(str_replace(' ', '', (string) $postcode))) === 1);
    }

    private static function territoryKey(string $country, ?string $postcode): string
    {
        if (self::isNorthernIreland($country, $postcode)) {
            return self::NORTHERN_IRELAND;
        }

        return self::vatCountry($country).'|'.(self::excludedTerritory($country, $postcode) ?? '');
    }

    /**
     * The member state of a VAT identifier prefix (EL → GR, XI stays XI), or null if the prefix
     * does not belong to the EU VAT area.
     */
    public static function memberStateOfVatId(string $vatId): ?string
    {
        $prefix = strtoupper(substr(trim($vatId), 0, 2));
        if ($prefix === self::NORTHERN_IRELAND) {
            return self::NORTHERN_IRELAND;
        }
        if ($prefix === 'GR') {
            return null;
        }
        $country = self::VAT_COUNTRY[$prefix] ?? $prefix;

        return in_array($country, self::EU_MEMBER_STATES, true) ? $country : null;
    }
}
