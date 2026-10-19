<?php

declare(strict_types=1);

namespace Quittio\Country\FR\Formats;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Formats\FormatChoice;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Formats\FormatRule;
use Quittio\Country\FR\Rules\FrenchRules;

/**
 * Invoices between businesses established in France are e-invoices in Factur-X, UBL or CII,
 * exchanged through an approved platform (plateforme agréée): large and mid-sized businesses
 * issue them from 1 September 2026, all from 1 September 2027; all receive them from 2026.
 * Domestic invoices to businesses get Factur-X (the format "zugferd", profile EN 16931). Sending
 * through a platform is not part of this pack.
 */
final readonly class FrenchFormatRule implements FormatRule
{
    public function resolve(InvoiceData $invoice): ?FormatChoice
    {
        if ($invoice->buyer->isConsumer || ! FrenchRules::inFrance($invoice->seller->address) || ! FrenchRules::inFrance($invoice->buyer->address)) {
            return null;
        }

        return new FormatChoice(FormatRegistry::ZUGFERD, 'en16931', 'Domestic invoice between businesses in France: Factur-X (e-invoicing reform from 1 September 2026).');
    }
}
