<?php

declare(strict_types=1);

namespace Quittio\Laravel\Testing;

use PHPUnit\Framework\Assert;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Documents\DocumentCalculator;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Rendering\RenderedDocument as CoreRenderedDocument;
use Quittio\Core\Rendering\RenderedInvoice as CoreRenderedInvoice;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Rendering\RenderedDocument;
use Quittio\Laravel\Rendering\RenderedInvoice;

/**
 * Calculates invoices but skips validation, XML and PDF. Records every render call.
 */
final class QuittioFake extends QuittioManager
{
    /** @var list<RenderedInvoice> */
    private array $rendered = [];

    /** @var list<RenderedDocument> */
    private array $documents = [];

    public function renderInvoice(InvoiceData $invoice, RenderOptions $options): RenderedInvoice
    {
        $invoice = (new En16931Calculator)->calculate($invoice);
        $format = FormatRegistry::withDefaults()->has($options->formats[0] ?? 'zugferd')
            ? FormatRegistry::withDefaults()->get($options->formats[0] ?? 'zugferd')
            : FormatRegistry::withDefaults()->get('zugferd');

        $result = new RenderedInvoice(new CoreRenderedInvoice(
            data: $invoice,
            format: $format->key,
            profile: $options->profile instanceof Profile ? $options->profile : null,
            validation: new ValidationResult,
            filenamePattern: 'Invoice_'.$invoice->number.'.{ext}',
            xml: $format->hasXml() ? '<?xml version="1.0" encoding="UTF-8"?><fake number="'.htmlspecialchars($invoice->number).'"/>' : null,
            pdf: $format->pdf ? "%PDF-1.7\n% fake invoice ".$invoice->number : null,
            html: $format->html ? '<p>'.htmlspecialchars($invoice->number).'</p>' : null,
            json: $format->json ? $invoice->toJson() : null,
        ));
        $this->rendered[] = $result;

        return $result;
    }

    public function renderDocument(DocumentData $document, RenderOptions $options): RenderedDocument
    {
        $document = (new DocumentCalculator)->calculate($document);
        $result = new RenderedDocument(new CoreRenderedDocument($document, '<p>'.htmlspecialchars($document->number).'</p>', $document->number.'.{ext}', "%PDF-1.7\n% fake document"));
        $this->documents[] = $result;

        return $result;
    }

    /**
     * @param  (callable(RenderedInvoice): bool)|null  $callback
     */
    public function assertRendered(?callable $callback = null): void
    {
        $matching = $callback === null ? $this->rendered : array_filter($this->rendered, $callback);

        Assert::assertNotEmpty($matching, $callback === null ? 'No invoice was rendered.' : 'No rendered invoice matches the given callback.');
    }

    public function assertRenderedCount(int $count): void
    {
        Assert::assertCount($count, $this->rendered, sprintf('Expected %d rendered invoices, got %d.', $count, count($this->rendered)));
    }

    public function assertNothingRendered(): void
    {
        Assert::assertSame([], $this->rendered, sprintf('Expected no rendered invoice, got %d.', count($this->rendered)));
    }

    /**
     * @param  (callable(RenderedDocument): bool)|null  $callback
     */
    public function assertDocumentRendered(?callable $callback = null): void
    {
        $matching = $callback === null ? $this->documents : array_filter($this->documents, $callback);

        Assert::assertNotEmpty($matching, 'No matching document was rendered.');
    }

    /**
     * @return list<RenderedInvoice>
     */
    public function rendered(): array
    {
        return $this->rendered;
    }
}
