<?php

declare(strict_types=1);

namespace Quittio\Core\Xml\Cii;

use DOMDocument;
use Quittio\Core\Data\Totals\Totals;
use Quittio\Core\Reading\FormatReader;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Xml\Cii\Internal\CiiDocumentReader;

/**
 * Reads UN/CEFACT Cross Industry Invoices (Factur-X/ZUGFeRD, XRechnung CII) into the data model.
 *
 * Declared totals are kept unchanged; differences to a recalculation are reported
 * by the invoice reader, not silently corrected.
 */
final class CiiReader implements FormatReader
{
    /** Deepest accepted sub line hierarchy (ParentLineID chains). */
    public const int MAX_SUBLINE_DEPTH = 32;

    public function canRead(DOMDocument $document): bool
    {
        $root = $document->documentElement;

        return $root?->namespaceURI === CiiWriter::NS_RSM && $root->localName === 'CrossIndustryInvoice';
    }

    public function read(DOMDocument $document, string $xml): ReadResult
    {
        return (new CiiDocumentReader)->read($document, $xml);
    }
}
