<?php

declare(strict_types=1);

namespace Quittio\Core\Calculation;

use Quittio\Core\Data\InvoiceData;

/**
 * Calculates line amounts, allowances and charges, the VAT breakdown and totals.
 */
interface TotalsCalculator
{
    public function calculate(InvoiceData $invoice): InvoiceData;
}
