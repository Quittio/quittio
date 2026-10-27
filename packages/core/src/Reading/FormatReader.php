<?php

declare(strict_types=1);

namespace Quittio\Core\Reading;

use DOMDocument;

/**
 * Reads one XML invoice format into the data model.
 */
interface FormatReader
{
    /**
     * Whether the reader handles the document, judged by its root element and namespace.
     * InvoiceReader uses the first reader that returns true.
     */
    public function canRead(DOMDocument $document): bool;

    /**
     * Maps the document into the data model; $xml is the XML the document was loaded from and goes
     * into the result.
     */
    public function read(DOMDocument $document, string $xml): ReadResult;
}
