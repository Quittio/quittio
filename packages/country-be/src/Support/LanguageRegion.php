<?php

declare(strict_types=1);

namespace Quittio\Country\BE\Support;

/**
 * The language region of a Belgian address by its post code: the Dutch language region
 * (Flanders), the French language region (Wallonia without the German-speaking municipalities),
 * the German language region and the bilingual region Brussels-Capital.
 *
 * The decrees on the use of languages (Flemish Community: decree of 19 July 1973; French
 * Community: decree of 30 June 1982) require documents of businesses that the law prescribes,
 * such as invoices, in the language of the region of the business's place of operation.
 */
final class LanguageRegion
{
    public const string DUTCH = 'nl';

    public const string FRENCH = 'fr';

    public const string GERMAN = 'de';

    /** Brussels-Capital: Dutch or French. */
    public const string BILINGUAL = 'bilingual';

    /**
     * The region of a Belgian post code, null for one that is not Belgian.
     */
    public static function of(?string $postcode): ?string
    {
        if ($postcode === null || preg_match('/^\d{4}$/', trim($postcode)) !== 1) {
            return null;
        }
        $code = (int) trim($postcode);

        return match (true) {
            $code >= 1000 && $code <= 1299 => self::BILINGUAL,
            ($code >= 1500 && $code <= 3999) || ($code >= 8000 && $code <= 9999) => self::DUTCH,
            // The nine German-speaking municipalities (Eupen, Kelmis, Lontzen, Raeren, Bütgenbach,
            // Büllingen, Amel, St. Vith, Burg-Reuland).
            $code >= 4700 && $code <= 4791 => self::GERMAN,
            ($code >= 1300 && $code <= 1499) || ($code >= 4000 && $code <= 7999) => self::FRENCH,
            default => null,
        };
    }
}
