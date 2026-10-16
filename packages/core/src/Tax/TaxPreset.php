<?php

declare(strict_types=1);

namespace Quittio\Core\Tax;

use Brick\Math\BigDecimal;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;

/**
 * A named VAT treatment provided by a country pack, e.g. "standard rate 19 %" or
 * "small business exemption". Presets set category, rate, VATEX code and legal note text.
 */
interface TaxPreset
{
    /**
     * Stable key, prefixed with the country code, e.g. "de.standard".
     */
    public function key(): string;

    /**
     * VAT category (BT-151).
     */
    public function category(): VatCategory;

    /**
     * VAT rate in percent (BT-152); null for categories without a rate (e.g. O).
     */
    public function rate(): ?BigDecimal;

    /**
     * VATEX code of the exemption reason (BT-121); null for taxed supplies.
     */
    public function exemptionReasonCode(): VatExemptionReasonCode|RawCode|null;

    /**
     * Exemption reason text (BT-120), shown in XML and PDF.
     */
    public function exemptionReason(?string $locale = null): ?string;
}
