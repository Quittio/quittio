<?php

declare(strict_types=1);

namespace Quittio\Core\Xml\Ubl;

use DOMDocument;
use Quittio\Core\Reading\FormatReader;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Xml\Ubl\Internal\UblDocumentReader;

/**
 * Reads UBL 2.1 Invoice and CreditNote documents (EN 16931, XRechnung UBL, Peppol BIS) into the data model.
 */
final class UblReader implements FormatReader
{
    /**
     * Whether the root element is a UBL 2.1 Invoice or CreditNote in its namespace.
     */
    public function canRead(DOMDocument $document): bool
    {
        $root = $document->documentElement;

        return ($root?->namespaceURI === UblWriter::NS_INVOICE && $root->localName === 'Invoice')
            || ($root?->namespaceURI === UblWriter::NS_CREDIT_NOTE && $root->localName === 'CreditNote');
    }

    /**
     * Reads the UBL document into invoice data, with the declared totals and VAT breakdown
     * unchanged and the findings of reading. $xml is the source text of $document, kept in the
     * result.
     */
    public function read(DOMDocument $document, string $xml): ReadResult
    {
        return (new UblDocumentReader)->read($document, $xml);
    }
}
