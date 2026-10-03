<?php

declare(strict_types=1);

namespace Quittio\Core\Calculation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowance;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Enums\AllowanceReasonCode;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Decimal;

/**
 * Splits a document discount over several VAT groups (specification 7.5).
 *
 * A document allowance has exactly one VAT category and rate. A discount on
 * all lines is therefore split into one allowance per VAT group. Rounding
 * differences are distributed with the largest remainder method, so that the
 * parts add up exactly to the total discount.
 *
 * For percentage discounts, base amount and percentage are kept on a part only
 * if the part equals round2(base × percent / 100); otherwise only the amount is
 * written, because validators check that formula (e.g. PEPPOL-EN16931-R040).
 */
final class AllowanceSplitter
{
    public function __construct(
        private readonly LineCalculator $lineCalculator = new LineCalculator,
    ) {}

    /**
     * @param  list<InvoiceLine>  $lines
     * @return list<DocumentAllowance>
     */
    public function splitAllowance(
        array $lines,
        string $currency,
        ?BigDecimal $percent,
        ?Money $amount,
        ?string $reason = null,
        AllowanceReasonCode|RawCode|null $reasonCode = null,
    ): array {
        if ($percent === null && $amount === null) {
            throw new CalculationException('A discount requires a percentage or an amount.');
        }

        /** @var array<string, array{category: \Quittio\Core\Enums\VatCategory, rate: ?BigDecimal, sum: BigDecimal}> $groups */
        $groups = [];
        $total = BigDecimal::zero();
        foreach ($lines as $line) {
            $calculated = $this->lineCalculator->calculate($line, $currency);
            $key = $line->vat->groupKey();
            $value = $calculated->netAmount?->getAmount() ?? BigDecimal::zero();
            $groups[$key] ??= ['category' => $line->vat->category, 'rate' => $line->vat->rate, 'sum' => BigDecimal::zero()];
            $groups[$key]['sum'] = $groups[$key]['sum']->plus($value);
            $total = $total->plus($value);
        }

        $discount = $amount !== null
            ? $amount->getAmount()
            : Decimal::round($total->multipliedBy($percent ?? BigDecimal::zero())->dividedBy(100, 10, RoundingMode::HalfUp), 2);

        $parts = LargestRemainder::distribute($discount, array_map(static fn (array $g): BigDecimal => $g['sum'], $groups));

        $result = [];
        foreach ($groups as $key => $group) {
            $part = $parts[$key];
            if ($part->isZero()) {
                continue;
            }

            $keepPercent = $percent !== null
                && Decimal::round($group['sum']->multipliedBy($percent)->dividedBy(100, 10, RoundingMode::HalfUp), 2)->isEqualTo($part);

            $result[] = new DocumentAllowance(
                amount: Amount::fromDecimal($part, $currency),
                vatCategory: $group['category'],
                vatRate: $group['rate'],
                baseAmount: $keepPercent ? Amount::fromDecimal($group['sum'], $currency) : null,
                percent: $keepPercent ? $percent : null,
                reason: $reason,
                reasonCode: $reasonCode,
            );
        }

        return $result;
    }
}
