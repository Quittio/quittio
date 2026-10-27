<?php

declare(strict_types=1);

namespace Quittio\Core\Calculation;

use Quittio\Core\Data\InvoiceData;

/**
 * Calculates line amounts, allowances and charges, the VAT breakdown and totals.
 */
interface TotalsCalculator
{
    /**
     * Returns $invoice with calculated line amounts, allowances and charges, VAT breakdown and
     * totals; the input stays unchanged.
     */
    public function calculate(InvoiceData $invoice): InvoiceData;
}
