<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use Quittio\Core\Data\Party\Address;

/**
 * Fallback address format: street lines, "postcode city", subdivision and, for
 * foreign recipients, the country name in upper case as the last line.
 */
final class InternationalAddressFormatter implements AddressFormatter
{
    /** Countries that put the postcode after the city. */
    private const array POSTCODE_AFTER_CITY = ['GB', 'IE', 'US', 'CA', 'AU', 'LV', 'UA'];

    public function format(Address $address, array $nameLines = [], ?string $senderCountry = null, ?string $locale = null): array
    {
        $lines = array_values(array_filter($nameLines, static fn (string $l): bool => trim($l) !== ''));

        foreach ([$address->line1, $address->line2, $address->line3] as $line) {
            if ($line !== null && trim($line) !== '') {
                $lines[] = $line;
            }
        }

        $city = trim((string) $address->city);
        $postcode = trim((string) $address->postcode);
        $cityLine = in_array($address->countryCode, self::POSTCODE_AFTER_CITY, true)
            ? trim($city.' '.$address->subdivision.' '.$postcode)
            : trim($postcode.' '.$city);

        if ($cityLine !== '') {
            $lines[] = preg_replace('/\s+/', ' ', $cityLine) ?? $cityLine;
        }

        if ($senderCountry !== null && strtoupper($senderCountry) !== $address->countryCode) {
            $lines[] = mb_strtoupper(CountryNames::name($address->countryCode, $locale ?? 'en'));
        }

        return $lines;
    }
}
