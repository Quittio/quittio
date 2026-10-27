<?php

declare(strict_types=1);

namespace Quittio\Core\Reading;

/**
 * An extractor that also reports where the XML came from and what it noticed on the way.
 */
interface DiagnosingXmlExtractor extends EmbeddedXmlExtractor
{
    /**
     * The embedded invoice XML with its file name, /AFRelationship and the findings of the
     * extractor, or null if the PDF embeds none. InvoiceReader adds the findings to the ReadResult.
     */
    public function extractFile(string $pdf): ?ExtractedXml;
}
