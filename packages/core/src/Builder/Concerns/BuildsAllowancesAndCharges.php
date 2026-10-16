<?php

declare(strict_types=1);

namespace Quittio\Core\Builder\Concerns;

use Brick\Math\BigDecimal;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Line;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\AllowanceReasonCode;
use Quittio\Core\Enums\ChargeReasonCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Support\Decimal;

/**
 * Document level allowances and charges (BG-20, BG-21) and discounts split over the VAT rates.
 *
 * @internal part of {@see InvoiceBuilder}, which holds the state
 */
trait BuildsAllowancesAndCharges
{
    /**
     * Document level allowance (BG-20) for one VAT category and rate.
     *
     * The exemption reason (BT-120/BT-121) is used for the VAT breakdown when no invoice
     * line of the same VAT group provides one, e.g. an exempt charge on a standard rated invoice.
     */
    public function allowance(
        VatCategory $category,
        BigDecimal|int|float|string|null $rate,
        ?string $amount = null,
        ?string $percent = null,
        ?string $reason = null,
        AllowanceReasonCode|RawCode|string|null $reasonCode = null,
        ?string $baseAmount = null,
        ?string $exemptionReason = null,
        VatExemptionReasonCode|RawCode|string|null $exemptionReasonCode = null,
    ): static {
        $this->allowanceCharges[] = [
            'charge' => false,
            'amount' => $amount,
            'percent' => $percent,
            'base' => $baseAmount,
            'category' => $category,
            'rate' => $rate === null ? null : (string) Decimal::rejectFloat($rate),
            'reason' => $reason,
            'code' => is_string($reasonCode) ? (AllowanceReasonCode::tryFrom($reasonCode) ?? new RawCode($reasonCode)) : $reasonCode,
            'exemptionReason' => $exemptionReason,
            'exemptionCode' => is_string($exemptionReasonCode) ? (VatExemptionReasonCode::tryFrom($exemptionReasonCode) ?? new RawCode($exemptionReasonCode)) : $exemptionReasonCode,
        ];

        return $this;
    }

    /**
     * Document level charge (BG-21) for one VAT category and rate. See allowance() for the exemption reason.
     */
    public function charge(
        VatCategory $category,
        BigDecimal|int|float|string|null $rate,
        ?string $amount = null,
        ?string $percent = null,
        ?string $reason = null,
        ChargeReasonCode|RawCode|string|null $reasonCode = null,
        ?string $baseAmount = null,
        ?string $exemptionReason = null,
        VatExemptionReasonCode|RawCode|string|null $exemptionReasonCode = null,
    ): static {
        $this->allowanceCharges[] = [
            'charge' => true,
            'amount' => $amount,
            'percent' => $percent,
            'base' => $baseAmount,
            'category' => $category,
            'rate' => $rate === null ? null : (string) Decimal::rejectFloat($rate),
            'reason' => $reason,
            'code' => is_string($reasonCode) ? (ChargeReasonCode::tryFrom($reasonCode) ?? new RawCode($reasonCode)) : $reasonCode,
            'exemptionReason' => $exemptionReason,
            'exemptionCode' => is_string($exemptionReasonCode) ? (VatExemptionReasonCode::tryFrom($exemptionReasonCode) ?? new RawCode($exemptionReasonCode)) : $exemptionReasonCode,
        ];

        return $this;
    }

    /**
     * Percentage discount on all lines. Split into one allowance per VAT group.
     */
    public function discountPercent(BigDecimal|int|float|string $percent, ?string $reason = null, AllowanceReasonCode|RawCode|string|null $reasonCode = AllowanceReasonCode::Discount): static
    {
        $this->splitAllowanceCharges[] = [
            'charge' => false,
            'percent' => (string) Decimal::rejectFloat($percent),
            'amount' => null,
            'reason' => $reason,
            'code' => is_string($reasonCode) ? (AllowanceReasonCode::tryFrom($reasonCode) ?? new RawCode($reasonCode)) : $reasonCode,
        ];

        return $this;
    }

    /**
     * Absolute discount distributed over all VAT groups in proportion to their line totals.
     */
    public function discountAmount(BigDecimal|int|float|string $amount, ?string $reason = null, AllowanceReasonCode|RawCode|string|null $reasonCode = AllowanceReasonCode::Discount): static
    {
        $this->splitAllowanceCharges[] = [
            'charge' => false,
            'percent' => null,
            'amount' => (string) Decimal::rejectFloat($amount),
            'reason' => $reason,
            'code' => is_string($reasonCode) ? (AllowanceReasonCode::tryFrom($reasonCode) ?? new RawCode($reasonCode)) : $reasonCode,
        ];

        return $this;
    }
}
