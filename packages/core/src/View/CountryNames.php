<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use Locale;

/**
 * Country names from ext-intl with the ISO code as fallback.
 */
final class CountryNames
{
    /**
     * Name of the country with ISO 3166-1 alpha-2 code $code in $locale, e.g. "Deutschland" for
     * "DE" in "de"; the code itself without ext-intl or for an unknown code.
     */
    public static function name(string $code, string $locale = 'en'): string
    {
        if (class_exists(Locale::class)) {
            $name = Locale::getDisplayRegion('-'.$code, $locale);
            if (is_string($name) && $name !== '' && $name !== $code) {
                return $name;
            }
        }

        return $code;
    }
}
