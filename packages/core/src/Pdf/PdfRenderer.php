<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf;

/**
 * Turns HTML (from the template) into a PDF, embedding the invoice XML if requested.
 */
interface PdfRenderer
{
    public function render(RenderRequest $request): string;

    /**
     * Whether the renderer creates the PDF/A and embeds the requested files itself (or with
     * its own post processor). Otherwise Quittio passes the result to MpdfPostProcessor when
     * the request asks for PDF/A or an embedded XML.
     */
    public function supportsEmbedding(): bool;
}
