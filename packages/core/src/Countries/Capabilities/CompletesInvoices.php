<?php

declare(strict_types=1);

namespace Quittio\Core\Countries\Capabilities;

use Quittio\Core\Data\InvoiceData;

/**
 * A country pack that completes an invoice before it is calculated, validated and rendered,
 * e.g. with a notice the law of the country expects for some buyers. The render pipeline calls
 * it for the leading pack of the invoice; what it adds is part of the rendered data.
 */
interface CompletesInvoices
{
    /**
     * The invoice with what the pack adds; the invoice itself if nothing is missing.
     *
     * @param  array<string, mixed>  $settings  the settings of the pack (Config::$countrySettings, e.g. quittio.countries.de)
     */
    public function complete(InvoiceData $invoice, array $settings): InvoiceData;
}
