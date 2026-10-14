<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Tax;

use Brick\Math\BigDecimal;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Tax\CustomTaxPreset;
use Quittio\Core\Tax\TaxPreset;
use Quittio\Country\DE\CountryDE;

/**
 * Tax presets for Germany (specification 8.2).
 *
 * The note texts and legal references are proposals and must be confirmed by a
 * tax adviser before the first release (legal review in the README).
 */
enum VatPreset: string implements TaxPreset
{
    case Standard19 = 'de.standard_19';
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

    public function key(): string
    {
        return $this->value;
    }

    public function category(): VatCategory
    {
        return match ($this) {
            self::Standard19, self::Reduced7 => VatCategory::StandardRate,
            self::ZeroRatedPv => VatCategory::ZeroRated,
            self::ReverseChargeDomestic, self::ReverseChargeEu, self::TriangularTransaction => VatCategory::ReverseCharge,
            self::IntraCommunitySupply => VatCategory::IntraCommunitySupply,
            self::Export => VatCategory::Export,
            self::NotTaxableInGermany => VatCategory::NotSubjectToVat,
            self::SmallBusiness, self::MarginSecondHand, self::MarginArt, self::MarginAntiques, self::TravelAgency => VatCategory::Exempt,
        };
    }

    public function rate(): ?BigDecimal
    {
        return match ($this) {
            self::Standard19 => BigDecimal::of('19'),
            self::Reduced7 => BigDecimal::of('7'),
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
        if ($this === self::Standard19 || $this === self::Reduced7 || $this === self::ZeroRatedPv) {
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
