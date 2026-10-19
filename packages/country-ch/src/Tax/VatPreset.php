<?php

declare(strict_types=1);

namespace Quittio\Country\CH\Tax;

use Brick\Math\BigDecimal;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Support\Date;
use Quittio\Core\Tax\CustomTaxPreset;
use Quittio\Core\Tax\DatedTaxPreset;
use Quittio\Core\Tax\TaxPreset;
use Quittio\Country\CH\CountryCH;

/**
 * Tax presets for Switzerland (MWSTG).
 *
 * Standard, Reduced and Accommodation take the rate that holds on the supply date of the line
 * (TaxTables), e.g. 7.7 % for supplies of 2023; rate() returns the rate of today.
 *
 * The texts are not reviewed by a tax adviser yet (see the README of the pack).
 */
enum VatPreset: string implements DatedTaxPreset
{
    /** Standard rate (Art. 25 (1) MWSTG), today 8.1 %. */
    case Standard = 'ch.standard';

    /** Reduced rate (Art. 25 (2) MWSTG), today 2.6 %. */
    case Reduced = 'ch.reduced';

    /** Special rate for accommodation (Art. 25 (4) MWSTG), today 3.8 %. */
    case Accommodation = 'ch.accommodation';

    /** Supply excluded from the tax (Art. 21 MWSTG), without input tax deduction. */
    case Excluded = 'ch.excluded';

    /** Supply exempt from the tax with input tax deduction, e.g. an export (Art. 23 MWSTG). */
    case Export = 'ch.export';

    /** Supply whose place is abroad (Art. 7 and 8 MWSTG), not taxable in Switzerland. */
    case NotTaxableInSwitzerland = 'ch.not_taxable';

    /** Seller not registered for VAT, e.g. below the turnover of CHF 100,000 (Art. 10 (2) MWSTG). */
    case NotRegistered = 'ch.not_registered';

    /**
     * Supply excluded from the tax with a specific legal reference, e.g.
     * VatPreset::excluded('Art. 21 Abs. 2 Ziff. 3 MWSTG'). The text defaults to German.
     */
    public static function excluded(string $paragraph, VatExemptionReasonCode|RawCode|string|null $code = null): TaxPreset
    {
        CountryCH::registerTranslations();
        $translator = Translations::translator();
        $reasons = [];
        foreach (CountryCH::locales() as $locale) {
            $reasons[$locale] = $translator->get(CountryCH::TRANSLATION_NAMESPACE.'::tax.ch.excluded_by', ['paragraph' => $paragraph], $locale);
        }

        return new CustomVatPreset(CustomTaxPreset::make(
            key: 'ch.excluded',
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
        $rate = match ($this) {
            self::Standard => TaxTables::standardRateOn($date),
            self::Reduced => TaxTables::reducedRateOn($date),
            self::Accommodation => TaxTables::accommodationRateOn($date),
            default => null,
        };

        return $rate === null ? $this : new CustomVatPreset(CustomTaxPreset::make(key: $this->value, category: VatCategory::StandardRate, rate: $rate));
    }

    public function category(): VatCategory
    {
        return match ($this) {
            self::Standard, self::Reduced, self::Accommodation => VatCategory::StandardRate,
            self::Excluded => VatCategory::Exempt,
            self::Export => VatCategory::Export,
            self::NotTaxableInSwitzerland, self::NotRegistered => VatCategory::NotSubjectToVat,
        };
    }

    public function rate(): ?BigDecimal
    {
        $today = Date::today('Europe/Zurich');

        return match ($this) {
            self::Standard => TaxTables::standardRateOn($today),
            self::Reduced => TaxTables::reducedRateOn($today),
            self::Accommodation => TaxTables::accommodationRateOn($today),
            self::NotTaxableInSwitzerland, self::NotRegistered => null,
            default => BigDecimal::zero(),
        };
    }

    public function exemptionReasonCode(): ?VatExemptionReasonCode
    {
        return match ($this) {
            self::Export => VatExemptionReasonCode::Export,
            self::NotTaxableInSwitzerland, self::NotRegistered => VatExemptionReasonCode::NotSubjectToVat,
            default => null,
        };
    }

    public function exemptionReason(?string $locale = null): ?string
    {
        if ($this->category() === VatCategory::StandardRate) {
            return null;
        }

        CountryCH::registerTranslations();

        // Country pack texts default to German.
        return Translations::translator()->get(CountryCH::TRANSLATION_NAMESPACE.'::tax.'.$this->value, [], $locale ?? 'de');
    }
}
