<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Tests\Support\Invoices;

/**
 * Property based test: for random invoices the sum rules BR-CO-10 to BR-CO-17 always hold.
 */
it('satisfies BR-CO-10 to BR-CO-17 for random invoices', function () {
    mt_srand(20261003);
    $rates = [['S', '19'], ['S', '7'], ['Z', '0'], ['E', '0'], ['S', '5.5']];
    $calculator = new En16931Calculator;

    for ($run = 0; $run < 500; $run++) {
        $builder = Invoices::base();
        $lineCount = mt_rand(1, 12);
        for ($i = 0; $i < $lineCount; $i++) {
            [$category, $rate] = $rates[mt_rand(0, count($rates) - 1)];
            $quantity = (string) (mt_rand(-3, 50) ?: 1);
            $price = sprintf('%d.%04d', mt_rand(0, 999), mt_rand(0, 9999));
            $builder->line(function ($l) use ($category, $rate, $quantity, $price) {
                $l->name('Item')->quantity($quantity)->netPrice($price)->vat(VatCategory::from($category), $rate, $category === 'E' ? 'Exempt' : null);
                if (mt_rand(0, 3) === 0) {
                    $l->allowance(percent: (string) mt_rand(1, 30), reason: 'Discount');
                }
            });
        }
        if (mt_rand(0, 2) === 0) {
            $builder->discountPercent((string) mt_rand(1, 20));
        }
        if (mt_rand(0, 3) === 0) {
            $builder->charge(VatCategory::StandardRate, '19', amount: sprintf('%d.%02d', mt_rand(0, 50), mt_rand(0, 99)), reason: 'Freight');
        }

        $invoice = $calculator->calculate($builder->build());
        $totals = $invoice->totals;
        expect($totals)->not->toBeNull();

        $sum = fn (array $values) => array_reduce($values, fn (BigDecimal $c, BigDecimal $v) => $c->plus($v), BigDecimal::zero());

        // BR-CO-10
        expect($totals->lineNetSum->getAmount()->isEqualTo($sum(array_map(fn ($l) => $l->netAmount->getAmount(), $invoice->lines))))->toBeTrue();
        // BR-CO-11, BR-CO-12
        expect(($totals->allowanceSum?->getAmount() ?? BigDecimal::zero())->isEqualTo($sum(array_map(fn ($a) => $a->amount->getAmount(), $invoice->allowances))))->toBeTrue();
        expect(($totals->chargeSum?->getAmount() ?? BigDecimal::zero())->isEqualTo($sum(array_map(fn ($c) => $c->amount->getAmount(), $invoice->charges))))->toBeTrue();
        // BR-CO-13
        expect($totals->netTotal->getAmount()->isEqualTo(
            $totals->lineNetSum->getAmount()
                ->minus($totals->allowanceSum?->getAmount() ?? BigDecimal::zero())
                ->plus($totals->chargeSum?->getAmount() ?? BigDecimal::zero())
        ))->toBeTrue();
        // BR-CO-14
        expect(($totals->vatTotal?->getAmount() ?? BigDecimal::zero())->isEqualTo($sum(array_map(fn ($b) => $b->taxAmount->getAmount(), $invoice->vatBreakdown))))->toBeTrue();
        // BR-CO-15
        expect($totals->grossTotal->getAmount()->isEqualTo($totals->netTotal->getAmount()->plus($totals->vatTotal?->getAmount() ?? BigDecimal::zero())))->toBeTrue();
        // BR-CO-16
        expect($totals->amountDue->getAmount()->isEqualTo(
            $totals->grossTotal->getAmount()
                ->minus($totals->prepaidAmount?->getAmount() ?? BigDecimal::zero())
                ->plus($totals->roundingAmount?->getAmount() ?? BigDecimal::zero())
        ))->toBeTrue();
        // BR-CO-17 and the taxable amount sum (BT-116 = Σ BT-109 parts)
        foreach ($invoice->vatBreakdown as $breakdown) {
            if ($breakdown->category === VatCategory::StandardRate) {
                $expected = $breakdown->taxableAmount->getAmount()->multipliedBy($breakdown->rate)->dividedBy(100, 2, RoundingMode::HalfUp);
                expect($breakdown->taxAmount->getAmount()->isEqualTo($expected))->toBeTrue();
            }
        }
        expect($sum(array_map(fn ($b) => $b->taxableAmount->getAmount(), $invoice->vatBreakdown))->isEqualTo($totals->netTotal->getAmount()))->toBeTrue();
    }
});
