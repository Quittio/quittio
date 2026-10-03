<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

use Quittio\Core\Data\InvoiceData;

/**
 * Selects the country packs for an invoice (specification 25.4).
 */
interface CountryResolver
{
    public function resolve(InvoiceData $invoice, ?string $countryOverride = null): ResolvedCountries;
}
