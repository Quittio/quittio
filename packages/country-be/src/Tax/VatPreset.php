<?php

declare(strict_types=1);

namespace Quittio\Country\BE\Tax;

use Brick\Math\BigDecimal;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Support\Date;
use Quittio\Core\Tax\CustomTaxPreset;
use Quittio\Core\Tax\DatedTaxPreset;
use Quittio\Core\Tax\TaxPreset;
use Quittio\Country\BE\CountryBE;

/**
 * Tax presets for Belgium (VAT Code, Royal Decree No. 20 on the rates).
 *
 * Accommodation takes the rate that holds on the supply date of the line (TaxTables): 12 % from
 * 1 March 2026, 6 % before. The texts default to Dutch and exist in Dutch, French, German and
 * English; they are not reviewed by a tax adviser yet (see the README of the pack).
 */
enum VatPreset: string implements DatedTaxPreset
{
    /** Standard rate, 21 %. */
    case Standard = 'be.standard';

    /** Intermediate rate, 12 %. */
    case Intermediate = 'be.intermediate';

    /** Reduced rate, 6 %. */
    case Reduced = 'be.reduced';

    /** Accommodation in hotels and campsites: 12 % from 1 March 2026, 6 % before. */
    case Accommodation = 'be.accommodation';

    /** Zero rate, e.g. for daily newspapers (category Z). */
    case ZeroRated = 'be.zero_rated';

    /** Small business exemption scheme (franchise, Art. 56bis VAT Code). */
    case SmallBusiness = 'be.small_business';

    /** Domestic reverse charge, e.g. works on immovable property (Art. 20 Royal Decree No. 1). */
    case ReverseChargeDomestic = 'be.reverse_charge_domestic';

    /** Service to a business in another member state, which owes the VAT (Art. 196 VAT Directive). */
    case ReverseChargeEu = 'be.reverse_charge_eu';

    /** Intra-community supply (Art. 39bis VAT Code). */
    case IntraCommunitySupply = 'be.intra_community_supply';

    /** Export (Art. 39 VAT Code). */
    case Export = 'be.export';

    /** Supply not taxable in Belgium (place of supply abroad). */
    case NotTaxableInBelgium = 'be.not_taxable';

    /** Margin scheme, second-hand goods (Art. 58 § 4 VAT Code). */
    case MarginSecondHand = 'be.margin_second_hand';

    /** Margin scheme, works of art (Art. 58 § 4 VAT Code). */
    case MarginArt = 'be.margin_art';

    /** Margin scheme, collectors' items and antiques (Art. 58 § 4 VAT Code). */
    case MarginAntiques = 'be.margin_antiques';

    /** Margin scheme of travel agencies (Art. 58 § 3 VAT Code). */
    case TravelAgency = 'be.travel_agency';

    /**
     * Tax exemption with a specific legal reference, e.g. VatPreset::exempt('artikel 44, § 2, 1°, Wetboek btw').
     */
    public static function exempt(string $paragraph, VatExemptionReasonCode|RawCode|string|null $code = null): TaxPreset
    {
        CountryBE::registerTranslations();
        $translator = Translations::translator();
        $reasons = [];
        foreach (CountryBE::locales() as $locale) {
            $reasons[$locale] = $translator->get(CountryBE::TRANSLATION_NAMESPACE.'::tax.be.exempt', ['paragraph' => $paragraph], $locale);
        }

        return new CustomVatPreset(CustomTaxPreset::make(
            key: 'be.exempt',
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
        return $this === self::Accommodation
            ? new CustomVatPreset(CustomTaxPreset::make(key: $this->value, category: VatCategory::StandardRate, rate: TaxTables::accommodationRateOn($date)))
            : $this;
    }

    public function category(): VatCategory
    {
        return match ($this) {
            self::Standard, self::Intermediate, self::Reduced, self::Accommodation => VatCategory::StandardRate,
            self::ZeroRated => VatCategory::ZeroRated,
            self::ReverseChargeDomestic, self::ReverseChargeEu => VatCategory::ReverseCharge,
            self::IntraCommunitySupply => VatCategory::IntraCommunitySupply,
            self::Export => VatCategory::Export,
            self::NotTaxableInBelgium => VatCategory::NotSubjectToVat,
            self::SmallBusiness, self::MarginSecondHand, self::MarginArt, self::MarginAntiques, self::TravelAgency => VatCategory::Exempt,
        };
    }

    public function rate(): ?BigDecimal
    {
        return match ($this) {
            self::Standard => BigDecimal::of(TaxTables::STANDARD_RATE),
            self::Intermediate => BigDecimal::of(TaxTables::INTERMEDIATE_RATE),
            self::Reduced => BigDecimal::of(TaxTables::REDUCED_RATE),
            self::Accommodation => TaxTables::accommodationRateOn(Date::today('Europe/Brussels')),
            self::NotTaxableInBelgium => null,
            default => BigDecimal::zero(),
        };
    }

    public function exemptionReasonCode(): ?VatExemptionReasonCode
    {
        return match ($this) {
            self::ReverseChargeDomestic, self::ReverseChargeEu => VatExemptionReasonCode::ReverseCharge,
            self::IntraCommunitySupply => VatExemptionReasonCode::IntraCommunitySupply,
            self::Export => VatExemptionReasonCode::Export,
            self::NotTaxableInBelgium => VatExemptionReasonCode::NotSubjectToVat,
            self::MarginSecondHand => VatExemptionReasonCode::SecondHandGoods,
            self::MarginArt => VatExemptionReasonCode::WorksOfArt,
            self::MarginAntiques => VatExemptionReasonCode::CollectorsItems,
            self::TravelAgency => VatExemptionReasonCode::TravelAgents,
            default => null,
        };
    }

    public function exemptionReason(?string $locale = null): ?string
    {
        // Taxed and zero-rated supplies carry no exemption reason (BR-S-10, BR-Z-10).
        if (in_array($this->category(), [VatCategory::StandardRate, VatCategory::ZeroRated], true)) {
            return null;
        }

        CountryBE::registerTranslations();

        return Translations::translator()->get(CountryBE::TRANSLATION_NAMESPACE.'::tax.'.$this->value, [], $locale ?? 'nl');
    }
}
