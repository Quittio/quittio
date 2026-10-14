<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Texts;

use Quittio\Core\Countries\LegalTextCatalog;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Tax\VatPreset;

/**
 * Mandatory and informational texts for German invoices (specification 8.2, 8.3).
 *
 * The notices that a tax preset also states (reverse charge, intra-Community supply, export,
 * small business) are the preset's exemption reason texts, so that one notice has one wording.
 *
 * The wording and legal references are proposals; confirm them with a tax adviser
 * before the first release (open decision 8).
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

    public const string ADVANCE_DEDUCTION = 'advance_deduction';

    /** One standard rated VAT row of a deducted advance invoice, part of ADVANCE_DEDUCTION. */
    public const string ADVANCE_DEDUCTION_TAXED = 'advance_deduction_taxed';

    /** One VAT row without VAT of a deducted advance invoice, part of ADVANCE_DEDUCTION. */
    public const string ADVANCE_DEDUCTION_UNTAXED = 'advance_deduction_untaxed';

    public const string CONSUMER_DEFAULT = 'consumer_default';

    public const string NEW_VEHICLE = 'new_vehicle';

    public const string FALLBACK_LOCALE = 'de';

    public static function catalog(): LegalTextCatalog
    {
        return new LegalTextCatalog([
            self::SELF_BILLING => [
                'de' => 'Gutschrift',
                'en' => 'Gutschrift (self-billed invoice)',
            ],
            self::PROPERTY_RETENTION => [
                'de' => 'Sie sind gesetzlich verpflichtet, diese Rechnung, einen Zahlungsbeleg oder eine andere beweiskräftige Unterlage zwei Jahre lang aufzubewahren (§ 14b Abs. 1 Satz 5 UStG).',
                'en' => 'You are required by law to keep this invoice, a payment receipt or other conclusive document for two years (section 14b (1) sentence 5 UStG).',
            ],
            self::REVERSE_CHARGE => self::presetTexts(VatPreset::ReverseChargeEu),
            self::INTRA_COMMUNITY_SUPPLY => self::presetTexts(VatPreset::IntraCommunitySupply),
            self::EXPORT => self::presetTexts(VatPreset::Export),
            self::SMALL_BUSINESS => self::presetTexts(VatPreset::SmallBusiness),
            self::PREPAYMENT => [
                'de' => 'Die Umsatzsteuer entsteht mit Vereinnahmung der Anzahlung. Die Leistung wird voraussichtlich am :date erbracht.',
                'en' => 'VAT becomes due on receipt of the prepayment. The service is expected to be performed on :date.',
            ],
            self::ADVANCE_DEDUCTION => [
                'de' => 'Abzüglich Anzahlung gemäß Rechnung :number vom :date: :amounts (§ 14 Abs. 5 Satz 2 UStG).',
                'en' => 'Less advance payment according to invoice :number of :date: :amounts (section 14 (5) sentence 2 UStG).',
            ],
            self::ADVANCE_DEDUCTION_TAXED => [
                'de' => 'Entgelt :net zzgl. :rate Umsatzsteuer :vat',
                'en' => 'net :net plus :rate VAT :vat',
            ],
            self::ADVANCE_DEDUCTION_UNTAXED => [
                'de' => 'Entgelt :net ohne Umsatzsteuer',
                'en' => 'net :net without VAT',
            ],
            self::CONSUMER_DEFAULT => [
                'de' => 'Sie kommen spätestens in Verzug, wenn Sie nicht innerhalb von 30 Tagen nach Fälligkeit und Zugang dieser Rechnung zahlen (§ 286 Abs. 3 BGB).',
                'en' => 'You will be in default at the latest if you do not pay within 30 days of the due date and receipt of this invoice (section 286 (3) BGB).',
            ],
            self::NEW_VEHICLE => [
                'de' => 'Innergemeinschaftliche Lieferung eines neuen Fahrzeugs (§ 14a Abs. 4 UStG); die Fahrzeugmerkmale nach § 1b Abs. 2 und 3 UStG sind bei der Position angegeben.',
                'en' => 'Intra-Community supply of a new means of transport (section 14a (4) UStG); the vehicle details under section 1b (2) and (3) UStG are stated with the line.',
            ],
        ], fallbackLocale: self::FALLBACK_LOCALE);
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
