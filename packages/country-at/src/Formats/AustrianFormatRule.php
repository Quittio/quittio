<?php

declare(strict_types=1);

namespace Quittio\Country\AT\Formats;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\ElectronicAddressScheme;
use Quittio\Core\Formats\FormatChoice;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Formats\FormatRule;

/**
 * Formats for Austrian public buyers: a buyer with an Austrian organisation number (electronic
 * address scheme 9915, e.g. "b" for the federal government on Peppol) receives UBL in the CIUS of
 * e-Rechnung.gv.at (CIUS-AT-GOV).
 *
 * There is no e-invoicing obligation between businesses in Austria; everything else is left to
 * the format resolver.
 */
final readonly class AustrianFormatRule implements FormatRule
{
    public function resolve(InvoiceData $invoice): ?FormatChoice
    {
        $endpoint = $invoice->buyer->electronicAddress;
        if ($endpoint !== null && (string) $endpoint->scheme->value === ElectronicAddressScheme::AustrianOrganisationNumber->value) {
            return new FormatChoice(FormatRegistry::UBL, 'cius-at-gov', 'Austrian public buyer (scheme 9915): UBL after CIUS-AT-GOV (e-Rechnung.gv.at).');
        }

        return null;
    }
}
