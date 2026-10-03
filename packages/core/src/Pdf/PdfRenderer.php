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
     * Whether the renderer creates PDF/A-3 with embedded files itself. Otherwise the
     * result is passed to a post processor.
     */
    public function supportsEmbedding(): bool;
}
