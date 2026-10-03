<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\PostProcessing;

use Quittio\Core\Pdf\EmbeddedXml;

/**
 * Turns an existing PDF into PDF/A-3 with embedded invoice XML (for callback and passthrough renderers).
 */
interface PdfPostProcessor
{
    public function process(string $pdf, ?EmbeddedXml $embedded, string $title, ?string $author = null): string;
}
