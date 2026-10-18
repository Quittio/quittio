<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Texts;

use Quittio\Core\Countries\LegalTextCatalog;
use Quittio\Core\I18n\Translations;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Tax\VatPreset;

/**
 * Mandatory and informational texts for German invoices.
 *
 * The notices that a tax preset also states (reverse charge, intra-Community supply, export,
 * small business) are the preset's exemption reason texts, so that one notice has one wording.
 *
 * The wording and legal references are proposals; confirm them with a tax adviser
 * before the first release (legal review in the README).
 */
final class LegalTexts
{
    public const string SELF_BILLING = 'self_billing';

    public const string PROPERTY_RETENTION = 'property_retention';

    public const string REVERSE_CHARGE = 'reverse_charge';

    public const string INTRA_COMMUNITY_SUPPLY = 'intra_community_supply';

    public const string EXPORT = 'export';

    public const string SMALL_BUSINESS = 'small_business';

    public const string PREPAYMENT = 'prepayment';

    /** The date of the service of a prepayment invoice is not agreed yet. */
    public const string SERVICE_DATE_OPEN = 'service_date_open';

    public const string ADVANCE_DEDUCTION = 'advance_deduction';

    /** One standard rated VAT row of a deducted advance invoice, part of ADVANCE_DEDUCTION. */
    public const string ADVANCE_DEDUCTION_TAXED = 'advance_deduction_taxed';

    /** One VAT row without VAT of a deducted advance invoice, part of ADVANCE_DEDUCTION. */
    public const string ADVANCE_DEDUCTION_UNTAXED = 'advance_deduction_untaxed';

    public const string CONSUMER_DEFAULT = 'consumer_default';

    public const string NEW_VEHICLE = 'new_vehicle';

    /** Reference to an agreement on bonuses, rebates or discounts not yet quantified (section 14 (4) no. 7 UStG). */
    public const string DISCOUNT_AGREEMENT = 'discount_agreement';

    /** Reference of the zero rate for photovoltaic systems (VatPreset::ZeroRatedPv adds it). */
    public const string ZERO_RATED_PV = 'zero_rated_pv';

    /** Description of a default interest charge of a payment reminder (DefaultInterest). */
    public const string DEFAULT_INTEREST = 'default_interest';

    public const string FALLBACK_LOCALE = 'de';

    /** Keys of the texts in resources/lang/{locale}/legal.php. */
    private const array TRANSLATED = [
        self::SELF_BILLING, self::PROPERTY_RETENTION, self::PREPAYMENT, self::SERVICE_DATE_OPEN, self::ADVANCE_DEDUCTION,
        self::ADVANCE_DEDUCTION_TAXED, self::ADVANCE_DEDUCTION_UNTAXED, self::CONSUMER_DEFAULT,
        self::NEW_VEHICLE, self::DEFAULT_INTEREST, self::ZERO_RATED_PV, self::DISCOUNT_AGREEMENT,
    ];

    /** @var array{0: int, 1: LegalTextCatalog}|null the catalog of a translator version */
    private static ?array $catalog = null;

    /**
     * The texts in every locale the pack ships. They come from the pack's language files
     * (resources/lang/{locale}/legal.php, the exemption reasons from tax.php) through the active
     * translator, so that lang/vendor/quittio-de overrides them in Laravel.
     */
    public static function catalog(): LegalTextCatalog
    {
        CountryDE::registerTranslations();
        if (self::$catalog !== null && self::$catalog[0] === Translations::version()) {
            return self::$catalog[1];
        }

        $translator = Translations::translator();
        $texts = [];
        foreach (self::TRANSLATED as $key) {
            foreach (CountryDE::locales() as $locale) {
                $id = CountryDE::TRANSLATION_NAMESPACE.'::legal.'.$key;
                if ($translator->has($id, $locale)) {
                    $texts[$key][$locale] = $translator->get($id, [], $locale);
                }
            }
        }
        $texts[self::REVERSE_CHARGE] = self::presetTexts(VatPreset::ReverseChargeEu);
        $texts[self::INTRA_COMMUNITY_SUPPLY] = self::presetTexts(VatPreset::IntraCommunitySupply);
        $texts[self::EXPORT] = self::presetTexts(VatPreset::Export);
        $texts[self::SMALL_BUSINESS] = self::presetTexts(VatPreset::SmallBusiness);

        $catalog = new LegalTextCatalog($texts, fallbackLocale: self::FALLBACK_LOCALE);
        self::$catalog = [Translations::version(), $catalog];

        return $catalog;
    }

    /**
     * The text of $key in $locale, in its language (e.g. English for "en_GB"), or else in German.
     *
     * @param  array<string, string>  $replace
     */
    public static function text(string $key, string $locale = self::FALLBACK_LOCALE, array $replace = []): string
    {
        return (string) self::catalog()->get($key, self::localeOf($key, $locale), $replace);
    }

    /**
     * The catalog locale text() uses for $key and $locale: $locale itself, else its language,
     * else the fallback German.
     */
    public static function localeOf(string $key, string $locale): string
    {
        $variants = self::catalog()->texts[$key] ?? [];
        $language = self::language($locale);

        return match (true) {
            isset($variants[$locale]) => $locale,
            isset($variants[$language]) => $language,
            default => self::FALLBACK_LOCALE,
        };
    }

    /**
     * The language of a locale, e.g. "de" for "de_AT" or "de-AT".
     */
    public static function language(string $locale): string
    {
        return strtolower(explode('_', str_replace('-', '_', $locale))[0]);
    }

    /**
     * The exemption reason of a preset in every locale the pack ships.
     *
     * @return array<string, string>
     */
    private static function presetTexts(VatPreset $preset): array
    {
        $texts = [];
        foreach (CountryDE::locales() as $locale) {
            $texts[$locale] = (string) $preset->exemptionReason($locale);
        }

        return $texts;
    }
}
