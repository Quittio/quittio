<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Renderers;

use Closure;
use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Pdf\PdfRenderer;
use Quittio\Core\Pdf\PostProcessing\MpdfPostProcessor;
use Quittio\Core\Pdf\PostProcessing\PdfPostProcessor;
use Quittio\Core\Pdf\RenderRequest;

/**
 * Own rendering function (e.g. Browsershot, Gotenberg) that returns PDF binary data.
 * PDF/A and the embedded XML are added by the post processor.
 */
final class CallbackRenderer implements PdfRenderer
{
    /**
     * @param  Closure(string, RenderRequest): string  $callback  receives HTML and request, returns the PDF
     */
    public function __construct(
        private readonly Closure $callback,
        private readonly PdfPostProcessor $postProcessor = new MpdfPostProcessor,
    ) {}

    /**
     * PDF/A and the embedded XML are added by the post processor of this renderer.
     */
    public function supportsEmbedding(): bool
    {
        return true;
    }

    /**
     * Calls the callback with the HTML and the request and returns its PDF; the post processor adds
     * PDF/A and the embedded XML when the request asks for them. Throws a PdfException when the
     * callback returns no PDF.
     */
    public function render(RenderRequest $request): string
    {
        $pdf = ($this->callback)($request->html, $request);
        if (! str_starts_with($pdf, '%PDF')) {
            throw new PdfException('The render callback did not return a PDF.');
        }

        return $request->pdfa || $request->embedded !== null
            ? $this->postProcessor->process($pdf, $request->embedded, $request->title, $request->author)
            : $pdf;
    }
}
