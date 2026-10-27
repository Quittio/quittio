<?php

declare(strict_types=1);

namespace Quittio\Core\Formats;

use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Data\InvoiceData;

/**
 * Chooses format and profile when none is given.
 */
interface FormatResolver
{
    /**
     * Format and profile for $invoice, chosen from the invoice data and its country packs.
     */
    public function resolve(InvoiceData $invoice, ResolvedCountries $countries): FormatChoice;
}
