<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\PostProcessing;

use Quittio\Core\Pdf\EmbeddedXml;

/**
 * Turns an existing PDF into PDF/A-3 with embedded invoice XML (for callback and passthrough renderers).
 */
interface PdfPostProcessor
{
    /**
     * Returns $pdf as PDF/A-3 with $embedded as associated file (none if null) and $title and
     * $author in the document metadata.
     */
    public function process(string $pdf, ?EmbeddedXml $embedded, string $title, ?string $author = null): string;
}
