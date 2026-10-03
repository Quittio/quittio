<?php

declare(strict_types=1);

namespace Quittio\Core\Formats;

use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Data\InvoiceData;

/**
 * Chooses format and profile when none is given (specification 9.3).
 */
interface FormatResolver
{
    public function resolve(InvoiceData $invoice, ResolvedCountries $countries): FormatChoice;
}
