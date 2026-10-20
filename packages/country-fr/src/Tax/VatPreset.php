<?php

declare(strict_types=1);

namespace Quittio\Country\FR\Tax;

use Brick\Math\BigDecimal;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Support\Date;
use Quittio\Core\Tax\CustomTaxPreset;
use Quittio\Core\Tax\TaxPreset;
use Quittio\Country\FR\CountryFR;

/**
 * Tax presets for metropolitan France (CGI, from 1 January 2027 recodified in the CIBS; the
 * references to the CGI remain accepted until 30 June 2028). exemptionReason() cites the CGI,
 * exemptionReasonOn() the CIBS for invoices issued from 1 January 2027.
 *
 * The texts are not reviewed by a tax adviser yet (see the README of the pack).
 */
enum VatPreset: string implements TaxPreset
{
    /** Issue date from which the texts cite the CIBS (ordonnance n° 2026-671, art. 17). */
    public const string CIBS_FROM = '2027-01-01';

    /** Standard rate, 20 %. */
    case Standard = 'fr.standard';

    /** Intermediate rate, 10 %. */
    case Intermediate = 'fr.intermediate';

    /** Reduced rate, 5.5 %. */
    case Reduced = 'fr.reduced';

    /** Particular rate, 2.1 %. */
    case Particular = 'fr.particular';

    /** Small business exemption (franchise en base, art. 293 B CGI; from 2027 art. L. 233-3 CIBS). */
    case SmallBusiness = 'fr.small_business';

    /** Domestic reverse charge, e.g. subcontracting in construction (art. 283-2 nonies CGI). */
    case ReverseChargeDomestic = 'fr.reverse_charge_domestic';

    /** Service to a business in another member state, which owes the VAT (art. 283-2 CGI, art. 196 VAT Directive). */
    case ReverseChargeEu = 'fr.reverse_charge_eu';

    /** Intra-community supply (art. 262 ter I CGI; from 2027 art. L. 213-12, 1° CIBS). */
    case IntraCommunitySupply = 'fr.intra_community_supply';

    /** Export (art. 262 I CGI; from 2027 art. L. 213-12, 2° CIBS). */
    case Export = 'fr.export';

    /** Supply not taxable in France (place of supply abroad). */
    case NotTaxableInFrance = 'fr.not_taxable';

    /** Margin scheme, second-hand goods (art. 297 A CGI). */
    case MarginSecondHand = 'fr.margin_second_hand';

    /** Margin scheme, works of art (art. 297 A CGI). */
    case MarginArt = 'fr.margin_art';

    /** Margin scheme, collectors' items and antiques (art. 297 A CGI). */
    case MarginAntiques = 'fr.margin_antiques';

    /** Margin scheme of travel agencies (art. 266-1 e CGI). */
    case TravelAgency = 'fr.travel_agency';

    /**
     * Tax exemption with a specific legal reference, e.g. VatPreset::exempt('article 261-4-1° du CGI').
     */
    public static function exempt(string $paragraph, VatExemptionReasonCode|RawCode|string|null $code = null): TaxPreset
    {
        CountryFR::registerTranslations();
        $translator = Translations::translator();
        $reasons = [];
        foreach (CountryFR::locales() as $locale) {
            $reasons[$locale] = $translator->get(CountryFR::TRANSLATION_NAMESPACE.'::tax.fr.exempt', ['paragraph' => $paragraph], $locale);
        }

        return new CustomVatPreset(CustomTaxPreset::make(
            key: 'fr.exempt',
            category: VatCategory::Exempt,
            rate: '0',
            code: $code,
            reason: $reasons,
        ));
    }

    /**
     * The case of a key, e.g. of LineVat::$presetKey; null for keys of other presets.
     */
    public static function fromKey(string $key): ?self
    {
        return self::tryFrom($key);
    }

    public function key(): string
    {
        return $this->value;
    }

    public function category(): VatCategory
    {
        return match ($this) {
            self::Standard, self::Intermediate, self::Reduced, self::Particular => VatCategory::StandardRate,
            self::ReverseChargeDomestic, self::ReverseChargeEu => VatCategory::ReverseCharge,
            self::IntraCommunitySupply => VatCategory::IntraCommunitySupply,
            self::Export => VatCategory::Export,
            self::NotTaxableInFrance => VatCategory::NotSubjectToVat,
            self::SmallBusiness, self::MarginSecondHand, self::MarginArt, self::MarginAntiques, self::TravelAgency => VatCategory::Exempt,
        };
    }

    public function rate(): ?BigDecimal
    {
        return match ($this) {
            self::Standard => BigDecimal::of('20'),
            self::Intermediate => BigDecimal::of('10'),
            self::Reduced => BigDecimal::of('5.5'),
            self::Particular => BigDecimal::of('2.1'),
            self::NotTaxableInFrance => null,
            default => BigDecimal::zero(),
        };
    }

    public function exemptionReasonCode(): ?VatExemptionReasonCode
    {
        return match ($this) {
            self::ReverseChargeDomestic, self::ReverseChargeEu => VatExemptionReasonCode::ReverseCharge,
            self::IntraCommunitySupply => VatExemptionReasonCode::IntraCommunitySupply,
            self::Export => VatExemptionReasonCode::Export,
            self::NotTaxableInFrance => VatExemptionReasonCode::NotSubjectToVat,
            self::MarginSecondHand => VatExemptionReasonCode::SecondHandGoods,
            self::MarginArt => VatExemptionReasonCode::WorksOfArt,
            self::MarginAntiques => VatExemptionReasonCode::CollectorsItems,
            self::TravelAgency => VatExemptionReasonCode::TravelAgents,
            default => null,
        };
    }

    public function exemptionReason(?string $locale = null): ?string
    {
        if ($this->category() === VatCategory::StandardRate) {
            return null;
        }

        CountryFR::registerTranslations();

        return Translations::translator()->get(CountryFR::TRANSLATION_NAMESPACE.'::tax.'.$this->value, [], $locale ?? 'fr');
    }

    /**
     * The exemption reason of an invoice issued on the date: from 1 January 2027 the texts that
     * cite an article of the CGI cite the CIBS instead.
     */
    public function exemptionReasonOn(Date $issueDate, ?string $locale = null): ?string
    {
        if ($issueDate->isBefore(Date::parse(self::CIBS_FROM)) || ! in_array($this, [self::SmallBusiness, self::IntraCommunitySupply, self::Export], true)) {
            return $this->exemptionReason($locale);
        }

        CountryFR::registerTranslations();

        return Translations::translator()->get(CountryFR::TRANSLATION_NAMESPACE.'::tax.'.$this->value.'_cibs', [], $locale ?? 'fr');
    }
}
