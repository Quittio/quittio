<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

use Quittio\Core\Data\InvoiceData;

/**
 * Selects the country packs for an invoice.
 */
interface CountryResolver
{
    /**
     * The leading pack (seller side) and, for cross-border invoices, the buyer's pack for $invoice;
     * $countryOverride replaces the seller's country.
     */
    public function resolve(InvoiceData $invoice, ?string $countryOverride = null): ResolvedCountries;
}
