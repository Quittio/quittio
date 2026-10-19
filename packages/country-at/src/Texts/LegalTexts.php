<?php

declare(strict_types=1);

namespace Quittio\Country\AT\Texts;

use Quittio\Core\Countries\LegalTextCatalog;
use Quittio\Core\I18n\Translations;
use Quittio\Country\AT\CountryAT;
use Quittio\Country\AT\Tax\VatPreset;

/**
 * Mandatory and informational texts for Austrian invoices.
 *
 * The notices that a tax preset also states (reverse charge, intra-community supply, export,
 * small business) are the preset's exemption reason texts, so that one notice has one wording.
 *
 * Not reviewed by a tax adviser yet (see the README of the pack).
 */
final class LegalTexts
{
    /** Label of a self-billed invoice (section 11 (8) no. 3 UStG). */
    public const string SELF_BILLING = 'self_billing';

    public const string REVERSE_CHARGE = 'reverse_charge';

    public const string INTRA_COMMUNITY_SUPPLY = 'intra_community_supply';

    public const string EXPORT = 'export';

    public const string SMALL_BUSINESS = 'small_business';

    /** Description of a default interest charge between businesses (DefaultInterest). */
    public const string DEFAULT_INTEREST = 'default_interest';

    /** Description of a default interest charge of a consumer (DefaultInterest). */
    public const string DEFAULT_INTEREST_CONSUMER = 'default_interest_consumer';

    public const string FALLBACK_LOCALE = 'de';

    /** Keys of the texts in resources/lang/{locale}/legal.php. */
    private const array TRANSLATED = [self::SELF_BILLING, self::DEFAULT_INTEREST, self::DEFAULT_INTEREST_CONSUMER];

    /** @var array{0: int, 1: LegalTextCatalog}|null the catalog of a translator version */
    private static ?array $catalog = null;

    /**
     * The texts in every locale the pack ships, from its language files through the active
     * translator, so that lang/vendor/quittio-at overrides them in Laravel.
     */
    public static function catalog(): LegalTextCatalog
    {
        CountryAT::registerTranslations();
        if (self::$catalog !== null && self::$catalog[0] === Translations::version()) {
            return self::$catalog[1];
        }

        $translator = Translations::translator();
        $texts = [];
        foreach (self::TRANSLATED as $key) {
            foreach (CountryAT::locales() as $locale) {
                $id = CountryAT::TRANSLATION_NAMESPACE.'::legal.'.$key;
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
        $language = strtolower(explode('_', str_replace('-', '_', $locale))[0]);

        return match (true) {
            isset($variants[$locale]) => $locale,
            isset($variants[$language]) => $language,
            default => self::FALLBACK_LOCALE,
        };
    }

    /**
     * The exemption reason of a preset in every locale the pack ships.
     *
     * @return array<string, string>
     */
    private static function presetTexts(VatPreset $preset): array
    {
        $texts = [];
        foreach (CountryAT::locales() as $locale) {
            $texts[$locale] = (string) $preset->exemptionReason($locale);
        }

        return $texts;
    }
}
