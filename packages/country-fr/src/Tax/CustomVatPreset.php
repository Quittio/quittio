<?php

declare(strict_types=1);

namespace Quittio\Country\FR\Tax;

use Brick\Math\BigDecimal;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Tax\CustomTaxPreset;
use Quittio\Core\Tax\TaxPreset;

/**
 * Ad-hoc preset of the French pack, e.g. from VatPreset::exempt(). Like the VatPreset cases, its
 * text defaults to French when the invoice has no locale; other locales without a text fall back
 * to English.
 */
final readonly class CustomVatPreset implements TaxPreset
{
    public function __construct(private CustomTaxPreset $preset) {}

    public function key(): string
    {
        return $this->preset->key();
    }

    public function category(): VatCategory
    {
        return $this->preset->category();
    }

    public function rate(): ?BigDecimal
    {
        return $this->preset->rate();
    }

    public function exemptionReasonCode(): VatExemptionReasonCode|RawCode|null
    {
        return $this->preset->exemptionReasonCode();
    }

    public function exemptionReason(?string $locale = null): ?string
    {
        return $this->preset->exemptionReason($locale ?? 'fr');
    }
}
