<?php

declare(strict_types=1);

namespace Quittio\Core\Tests\Support;

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Enums\AllowanceReasonCode;
use Quittio\Core\Enums\ChargeReasonCode;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Testing\Scenarios;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Valid invoices built from a seed: the same seed gives the same invoice on every run.
 *
 * Varies the type (invoice, credit note), lines with positive and negative quantities, prices
 * with up to four decimals, VAT groups (S 19/7, Z, E, AE), line and document allowances and
 * charges with reason codes, a VAT currency (BT-6) with exchange rate and, on request, sub lines.
 */
final class RandomInvoices
{
    /** @var list<array{0: VatCategory, 1: string, 2: ?string, 3: ?string}> category, rate, exemption reason, code */
    private const array VAT = [
        [VatCategory::StandardRate, '19', null, null],
        [VatCategory::StandardRate, '7', null, null],
        [VatCategory::ZeroRated, '0', null, null],
        [VatCategory::Exempt, '0', 'Steuerfrei nach § 4 Nr. 14 UStG', null],
        [VatCategory::ReverseCharge, '0', 'Reverse charge', 'VATEX-EU-AE'],
    ];

    private const array UNITS = [UnitCode::Piece, UnitCode::Hour, UnitCode::Day, UnitCode::Kilogram];

    private const array ALLOWANCE_CODES = [AllowanceReasonCode::Discount, AllowanceReasonCode::OtherBonus, null];

    private const array CHARGE_CODES = [ChargeReasonCode::Freight, ChargeReasonCode::Packing, ChargeReasonCode::Miscellaneous, null];

    public static function make(int $seed, bool $subLines = false): InvoiceBuilder
    {
        $random = new Randomizer(new Mt19937($seed));
        $builder = Scenarios::base()->number(sprintf('GEN-%05d', $seed));

        if ($random->getInt(0, 3) === 0) {
            $builder->type(InvoiceTypeCode::CreditNote)->precedingInvoice('RE-2026-0001', '2026-09-01');
        }
        if ($random->getInt(0, 4) === 0) {
            $builder->currency('USD')->taxCurrency('EUR', self::decimal($random, 0, 2, 6, 1), '2026-10-02');
        }

        $groups = [];
        $lines = $random->getInt(1, 5);
        for ($i = 0; $i < $lines; $i++) {
            $vat = self::VAT[$random->getInt(0, count(self::VAT) - 1)];
            $groups[$vat[0]->value.'|'.$vat[1]] = $vat;
            // At most the last line has a negative quantity, smaller than the others together.
            $negative = $i > 0 && $i === $lines - 1 && $random->getInt(0, 2) === 0;
            $builder->line(function (LineBuilder $line) use ($random, $vat, $negative, $subLines, $i): void {
                $line->name('Position '.($i + 1))
                    ->quantity(($negative ? '-' : '').self::decimal($random, 1, 20, 2), self::UNITS[$random->getInt(0, count(self::UNITS) - 1)])
                    ->vat($vat[0], $vat[1], $vat[2], $vat[3]);
                if ($subLines && $random->getInt(0, 1) === 0) {
                    for ($s = 0; $s < $random->getInt(1, 3); $s++) {
                        $line->subLine(fn (LineBuilder $sub) => $sub->name('Teil '.($s + 1))->quantity($random->getInt(1, 5), UnitCode::Piece)->netPrice(self::decimal($random, 1, 200, 2))->vat($vat[0], $vat[1], $vat[2], $vat[3]));
                    }

                    return;
                }
                $line->netPrice(self::decimal($random, 0, 500, $random->getInt(0, 4), 1));
                match ($random->getInt(0, 4)) {
                    0 => $line->allowance(percent: (string) $random->getInt(1, 15), reason: 'Rabatt', reasonCode: self::ALLOWANCE_CODES[$random->getInt(0, 2)]),
                    1 => $line->charge(amount: self::decimal($random, 0, 10, 2, 1), reason: 'Zuschlag', reasonCode: self::CHARGE_CODES[$random->getInt(0, 3)]),
                    default => null,
                };
            });
        }

        foreach ($groups as [$category, $rate, $reason, $code]) {
            match ($random->getInt(0, 4)) {
                0 => $builder->allowance($category, $rate, percent: (string) $random->getInt(1, 5), reason: 'Treuerabatt', reasonCode: self::ALLOWANCE_CODES[$random->getInt(0, 2)]),
                1 => $builder->charge($category, $rate, amount: self::decimal($random, 1, 30, 2), reason: 'Fracht', reasonCode: self::CHARGE_CODES[$random->getInt(0, 3)], exemptionReason: $reason, exemptionReasonCode: $code),
                default => null,
            };
        }

        return $builder;
    }

    /**
     * A positive decimal string between $min and $max with $scale decimals.
     */
    private static function decimal(Randomizer $random, int $min, int $max, int $scale, int $minimum = 0): string
    {
        $factor = 10 ** $scale;
        $value = $random->getInt(max($min * $factor, $minimum), $max * $factor);

        return $scale === 0 ? (string) $value : sprintf('%d.%0'.$scale.'d', intdiv($value, $factor), $value % $factor);
    }
}
