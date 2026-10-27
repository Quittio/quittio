<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf;

/**
 * Turns HTML (from the template) into a PDF, embedding the invoice XML if requested.
 */
interface PdfRenderer
{
    /**
     * Renders the HTML of $request and returns the PDF as binary string. A renderer that
     * supportsEmbedding() also writes the PDF/A and the embedded XML the request asks for. Throws a
     * PdfException when rendering fails.
     */
    public function render(RenderRequest $request): string;

    /**
     * Whether the renderer creates the PDF/A and embeds the requested files itself (or with
     * its own post processor). Otherwise Quittio passes the result to MpdfPostProcessor when
     * the request asks for PDF/A or an embedded XML.
     */
    public function supportsEmbedding(): bool;
}
