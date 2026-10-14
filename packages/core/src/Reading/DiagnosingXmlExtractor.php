<?php

declare(strict_types=1);

namespace Quittio\Core\Reading;

/**
 * An extractor that also reports where the XML came from and what it noticed on the way.
 */
interface DiagnosingXmlExtractor extends EmbeddedXmlExtractor
{
    public function extractFile(string $pdf): ?ExtractedXml;
}
