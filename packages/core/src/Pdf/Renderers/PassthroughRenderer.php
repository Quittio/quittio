<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Renderers;

use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Pdf\PdfRenderer;
use Quittio\Core\Pdf\PostProcessing\MpdfPostProcessor;
use Quittio\Core\Pdf\PostProcessing\PdfPostProcessor;
use Quittio\Core\Pdf\RenderRequest;

/**
 * Uses a finished PDF supplied by the application; only PDF/A and the XML are added.
 */
final class PassthroughRenderer implements PdfRenderer
{
    public function __construct(
        private readonly string $pdf,
        private readonly PdfPostProcessor $postProcessor = new MpdfPostProcessor,
    ) {
        if (! str_starts_with($pdf, '%PDF')) {
            throw new PdfException('The supplied content is not a PDF.');
        }
    }

    /**
     * PDF/A and the embedded XML are added by the post processor of this renderer.
     */
    public function supportsEmbedding(): bool
    {
        return true;
    }

    public function render(RenderRequest $request): string
    {
        return $request->pdfa || $request->embedded !== null
            ? $this->postProcessor->process($this->pdf, $request->embedded, $request->title, $request->author)
            : $this->pdf;
    }
}
