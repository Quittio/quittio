<?php

declare(strict_types=1);

namespace Quittio\Country\CH\Rounding;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Support\Amount;

/**
 * Rounding of the amount due to 5 Rappen, as usual in Switzerland: the difference is the
 * rounding amount (BT-114), never a change of the VAT or the line amounts.
 */
final class CashRounding
{
    /**
     * The invoice with the rounding amount that makes the amount due a multiple of 0.05; invoices
     * in another currency than CHF and invoices with a rounding amount of their own stay as they are.
     */
    public static function apply(InvoiceData $invoice): InvoiceData
    {
        if ($invoice->currency !== 'CHF' || $invoice->roundingAmount !== null) {
            return $invoice;
        }

        $due = (new En16931Calculator)->calculate($invoice)->totals?->amountDue->getAmount();
        if ($due === null) {
            return $invoice;
        }
        $rounded = self::round($due);

        return $rounded->isEqualTo($due) ? $invoice : $invoice->with(['roundingAmount' => Amount::fromDecimal($rounded->minus($due), 'CHF')]);
    }

    /**
     * An amount rounded to 5 Rappen, half up: 10.02 becomes 10.00, 10.03 becomes 10.05.
     */
    public static function round(BigDecimal $amount): BigDecimal
    {
        return $amount->multipliedBy(20)->toScale(0, RoundingMode::HalfUp)->dividedBy(20, 2);
    }
}
