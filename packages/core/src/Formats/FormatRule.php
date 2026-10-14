<?php

declare(strict_types=1);

namespace Quittio\Core\Formats;

use Quittio\Core\Data\InvoiceData;

/**
 * A rule of a country pack that decides the output format of an invoice.
 */
interface FormatRule
{
    /**
     * Returns a format for the invoice, or null if the rule does not apply.
     */
    public function resolve(InvoiceData $invoice): ?FormatChoice;
}
