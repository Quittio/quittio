<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Tax;

use Brick\Math\BigDecimal;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Support\Date;
use Quittio\Core\Tax\CustomTaxPreset;
use Quittio\Core\Tax\DatedTaxPreset;
use Quittio\Core\Tax\TaxPreset;
use Quittio\Country\DE\CountryDE;

/**
 * Tax presets for Germany.
 *
 * Standard and Reduced take the rate that holds on the supply date of the line (TaxTables), e.g.
 * 16 % and 5 % for supplies in the second half of 2020; rate() returns the rate of today.
 *
 * The note texts and legal references are proposals and must be confirmed by a
 * tax adviser before the first release (legal review in the README).
 */
enum VatPreset: string implements DatedTaxPreset
{
    /** Standard rate (section 12 (1) UStG) of the supply date, today 19 %. */
    case Standard = 'de.standard';

    /** Reduced rate (section 12 (2) UStG) of the supply date, today 7 %. */
    case Reduced = 'de.reduced';

    /**
     * @deprecated use Standard; the key "de.standard_19" of stored data stays readable
     */
    case Standard19 = 'de.standard_19';

    /**
     * @deprecated use Reduced; the key "de.reduced_7" of stored data stays readable
     */
    case Reduced7 = 'de.reduced_7';

    case ZeroRatedPv = 'de.zero_rated_pv';
    case SmallBusiness = 'de.small_business';
    case ReverseChargeDomestic = 'de.reverse_charge_domestic';
    case ReverseChargeEu = 'de.reverse_charge_eu';
    case IntraCommunitySupply = 'de.intra_community_supply';
    case TriangularTransaction = 'de.triangular_transaction';
    case Export = 'de.export';
    case NotTaxableInGermany = 'de.not_taxable';
    case MarginSecondHand = 'de.margin_second_hand';
    case MarginArt = 'de.margin_art';
    case MarginAntiques = 'de.margin_antiques';
    case TravelAgency = 'de.travel_agency';

    /**
     * Tax exemption with a specific legal reference, e.g. VatPreset::exempt('§ 4 Nr. 14 UStG'). The
     * text ("de.exempt" in tax.php) defaults to German like the cases.
     */
    public static function exempt(string $paragraph, VatExemptionReasonCode|RawCode|string|null $code = null): TaxPreset
    {
        CountryDE::registerTranslations();
        $translator = Translations::translator();
        $reasons = [];
        foreach (CountryDE::locales() as $locale) {
            $reasons[$locale] = $translator->get(CountryDE::TRANSLATION_NAMESPACE.'::tax.de.exempt', ['paragraph' => $paragraph], $locale);
        }

        return new CustomVatPreset(CustomTaxPreset::make(
            key: 'de.exempt',
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

    /**
     * The preset without a rate in its name: Standard for Standard19, Reduced for Reduced7.
     */
    public function canonical(): self
    {
        // By value: the deprecated cases are not referenced by name.
        return match ($this->value) {
            'de.standard_19' => self::Standard,
            'de.reduced_7' => self::Reduced,
            default => $this,
        };
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
        $rate = match ($this->canonical()) {
            self::Standard => TaxTables::standardRateOn($date),
            self::Reduced => TaxTables::reducedRateOn($date),
            default => null,
        };

        return $rate === null ? $this : new CustomVatPreset(CustomTaxPreset::make(key: $this->value, category: VatCategory::StandardRate, rate: $rate));
    }

    public function category(): VatCategory
    {
        return match ($this->canonical()) {
            self::Standard, self::Reduced => VatCategory::StandardRate,
            self::ZeroRatedPv => VatCategory::ZeroRated,
            self::ReverseChargeDomestic, self::ReverseChargeEu, self::TriangularTransaction => VatCategory::ReverseCharge,
            self::IntraCommunitySupply => VatCategory::IntraCommunitySupply,
            self::Export => VatCategory::Export,
            self::NotTaxableInGermany => VatCategory::NotSubjectToVat,
            self::SmallBusiness, self::MarginSecondHand, self::MarginArt, self::MarginAntiques, self::TravelAgency => VatCategory::Exempt,
            // Never reached: canonical() turns the deprecated cases into Standard and Reduced.
            default => VatCategory::StandardRate,
        };
    }

    public function rate(): ?BigDecimal
    {
        return match ($this->canonical()) {
            self::Standard => TaxTables::standardRateOn(Date::today('Europe/Berlin')),
            self::Reduced => TaxTables::reducedRateOn(Date::today('Europe/Berlin')),
            self::NotTaxableInGermany => null,
            default => BigDecimal::zero(),
        };
    }

    public function exemptionReasonCode(): ?VatExemptionReasonCode
    {
        return match ($this) {
            self::ReverseChargeDomestic, self::ReverseChargeEu, self::TriangularTransaction => VatExemptionReasonCode::ReverseCharge,
            self::IntraCommunitySupply => VatExemptionReasonCode::IntraCommunitySupply,
            self::Export => VatExemptionReasonCode::Export,
            self::NotTaxableInGermany => VatExemptionReasonCode::NotSubjectToVat,
            self::MarginSecondHand => VatExemptionReasonCode::SecondHandGoods,
            self::MarginArt => VatExemptionReasonCode::WorksOfArt,
            self::MarginAntiques => VatExemptionReasonCode::CollectorsItems,
            self::TravelAgency => VatExemptionReasonCode::TravelAgents,
            default => null,
        };
    }

    public function exemptionReason(?string $locale = null): ?string
    {
        // Taxed and zero-rated supplies carry no exemption reason (BR-S-10, BR-Z-10): the 0 % rate
        // for photovoltaic systems (section 12 (3) UStG) is stated as the rate itself.
        if ($this->category() === VatCategory::StandardRate || $this === self::ZeroRatedPv) {
            return null;
        }

        CountryDE::registerTranslations();
        $translator = Translations::translator();
        $key = CountryDE::TRANSLATION_NAMESPACE.'::tax.'.$this->value;

        // Country pack texts default to German, the language of the invoice in Germany.
        $locale ??= 'de';

        return $translator->has($key, $locale) || $translator->has($key, 'en')
            ? $translator->get($key, [], $locale)
            : null;
    }
}
