<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Formats;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Formats\FormatChoice;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Formats\FormatRule;
use Quittio\Country\DE\Support\Flags;
use Quittio\Country\DE\Support\LeitwegId;

/**
 * Format choice for domestic German invoices (specification 9.3 no. 3):
 * public buyers (Leitweg-ID) receive XRechnung, consumers and small amount invoices a PDF,
 * all other businesses ZUGFeRD in profile EN 16931.
 */
final readonly class GermanFormatRule implements FormatRule
{
    /** Electronic address scheme of the Leitweg-ID (BT-49). */
    public const string LEITWEG_SCHEME = '0204';

    public function resolve(InvoiceData $invoice): FormatChoice
    {
        $endpoint = $invoice->buyer->electronicAddress;
        if (LeitwegId::looksLike($invoice->buyerReference) || ($endpoint !== null && (string) $endpoint->scheme->value === self::LEITWEG_SCHEME)) {
            return new FormatChoice(FormatRegistry::XRECHNUNG_CII, 'xrechnung', 'Public buyer with Leitweg-ID: XRechnung (E-Rechnungsverordnung).');
        }

        if ($invoice->meta->hasFlag(Flags::SMALL_AMOUNT)) {
            return new FormatChoice(FormatRegistry::PDF, null, 'Small amount invoice (section 33 UStDV): no e-invoice obligation.');
        }

        if ($invoice->buyer->isConsumer) {
            return new FormatChoice(FormatRegistry::PDF, null, 'Invoice to a consumer: no e-invoice obligation.');
        }

        return new FormatChoice(FormatRegistry::ZUGFERD, 'en16931', 'Domestic B2B invoice: ZUGFeRD in profile EN 16931.');
    }
}
