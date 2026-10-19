<?php

declare(strict_types=1);

namespace Quittio\Country\AT\Tax;

use Brick\Math\BigDecimal;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Support\Date;
use Quittio\Core\Tax\CustomTaxPreset;
use Quittio\Core\Tax\DatedTaxPreset;
use Quittio\Core\Tax\TaxPreset;
use Quittio\Country\AT\CountryAT;

/**
 * Tax presets for Austria (UStG 1994).
 *
 * BasicFood takes the rate that holds on the supply date of the line (TaxTables): 4.9 % from
 * 1 July 2026, 10 % before; rate() returns the rate of today.
 *
 * The texts are not reviewed by a tax adviser yet (see the README of the pack).
 */
enum VatPreset: string implements DatedTaxPreset
{
    /** Standard rate, 20 % (section 10 (1) UStG). */
    case Standard = 'at.standard';

    /** Reduced rate, 10 % (section 10 (2) UStG). */
    case Reduced = 'at.reduced';

    /** Second reduced rate, 13 % (section 10 (3) UStG). */
    case Reduced13 = 'at.reduced_13';

    /** Basic foods of section 10 (1a) UStG: 4.9 % from 1 July 2026, 10 % before. */
    case BasicFood = 'at.basic_food';

    /** Small business exemption (Kleinunternehmer, section 6 (1) no. 27 UStG). */
    case SmallBusiness = 'at.small_business';

    /** Domestic reverse charge (section 19 (1a) to (1e) UStG). */
    case ReverseChargeDomestic = 'at.reverse_charge_domestic';

    /** Service to a business in another member state, which owes the VAT there (section 19 (1) sentence 2 UStG). */
    case ReverseChargeEu = 'at.reverse_charge_eu';

    /** Intra-community supply (Art. 6 (1) with Art. 7 of the annex to the UStG, Binnenmarktregelung). */
    case IntraCommunitySupply = 'at.intra_community_supply';

    /** Intra-community triangular transaction (Art. 25 of the annex to the UStG). */
    case TriangularTransaction = 'at.triangular_transaction';

    /** Export supply (section 6 (1) no. 1 with section 7 UStG). */
    case Export = 'at.export';

    /** Supply not taxable in Austria (place of supply abroad). */
    case NotTaxableInAustria = 'at.not_taxable';

    /** Margin scheme, second-hand goods (section 24 UStG). */
    case MarginSecondHand = 'at.margin_second_hand';

    /** Margin scheme, works of art (section 24 UStG). */
    case MarginArt = 'at.margin_art';

    /** Margin scheme, collectors' items and antiques (section 24 UStG). */
    case MarginAntiques = 'at.margin_antiques';

    /** Margin scheme of travel agencies (section 23 UStG). */
    case TravelAgency = 'at.travel_agency';

    /**
     * Tax exemption with a specific legal reference, e.g. VatPreset::exempt('§ 6 Abs. 1 Z 19 UStG').
     * The text ("at.exempt" in tax.php) defaults to German like the cases.
     */
    public static function exempt(string $paragraph, VatExemptionReasonCode|RawCode|string|null $code = null): TaxPreset
    {
        CountryAT::registerTranslations();
        $translator = Translations::translator();
        $reasons = [];
        foreach (CountryAT::locales() as $locale) {
            $reasons[$locale] = $translator->get(CountryAT::TRANSLATION_NAMESPACE.'::tax.at.exempt', ['paragraph' => $paragraph], $locale);
        }

        return new CustomVatPreset(CustomTaxPreset::make(
            key: 'at.exempt',
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

    /**
     * The preset with the rate of the date; presets without a dated rate return themselves.
     */
    public function on(Date $date): TaxPreset
    {
        return $this === self::BasicFood
            ? new CustomVatPreset(CustomTaxPreset::make(key: $this->value, category: VatCategory::StandardRate, rate: TaxTables::basicFoodRateOn($date)))
            : $this;
    }

    public function category(): VatCategory
    {
        return match ($this) {
            self::Standard, self::Reduced, self::Reduced13, self::BasicFood => VatCategory::StandardRate,
            self::ReverseChargeDomestic, self::ReverseChargeEu, self::TriangularTransaction => VatCategory::ReverseCharge,
            self::IntraCommunitySupply => VatCategory::IntraCommunitySupply,
            self::Export => VatCategory::Export,
            self::NotTaxableInAustria => VatCategory::NotSubjectToVat,
            self::SmallBusiness, self::MarginSecondHand, self::MarginArt, self::MarginAntiques, self::TravelAgency => VatCategory::Exempt,
        };
    }

    public function rate(): ?BigDecimal
    {
        return match ($this) {
            self::Standard => BigDecimal::of(TaxTables::STANDARD_RATE),
            self::Reduced => BigDecimal::of(TaxTables::REDUCED_RATE),
            self::Reduced13 => BigDecimal::of(TaxTables::REDUCED_RATE_13),
            self::BasicFood => TaxTables::basicFoodRateOn(Date::today('Europe/Vienna')),
            self::NotTaxableInAustria => null,
            default => BigDecimal::zero(),
        };
    }

    public function exemptionReasonCode(): ?VatExemptionReasonCode
    {
        return match ($this) {
            self::ReverseChargeDomestic, self::ReverseChargeEu, self::TriangularTransaction => VatExemptionReasonCode::ReverseCharge,
            self::IntraCommunitySupply => VatExemptionReasonCode::IntraCommunitySupply,
            self::Export => VatExemptionReasonCode::Export,
            self::NotTaxableInAustria => VatExemptionReasonCode::NotSubjectToVat,
            self::MarginSecondHand => VatExemptionReasonCode::SecondHandGoods,
            self::MarginArt => VatExemptionReasonCode::WorksOfArt,
            self::MarginAntiques => VatExemptionReasonCode::CollectorsItems,
            self::TravelAgency => VatExemptionReasonCode::TravelAgents,
            default => null,
        };
    }

    public function exemptionReason(?string $locale = null): ?string
    {
        // Taxed supplies carry no exemption reason (BR-S-10).
        if ($this->category() === VatCategory::StandardRate) {
            return null;
        }

        CountryAT::registerTranslations();
        $translator = Translations::translator();
        $key = CountryAT::TRANSLATION_NAMESPACE.'::tax.'.$this->value;

        // Country pack texts default to German, the language of the invoice in Austria.
        return $translator->get($key, [], $locale ?? 'de');
    }
}
