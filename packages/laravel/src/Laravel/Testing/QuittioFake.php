<?php

declare(strict_types=1);

namespace Quittio\Laravel\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use LogicException;
use PHPUnit\Framework\Assert;
use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Documents\DocumentCalculator;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Quittio;
use Quittio\Core\Rendering\RenderedDocument as CoreRenderedDocument;
use Quittio\Core\Rendering\RenderedInvoice as CoreRenderedInvoice;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Rendering\RenderedDocument;
use Quittio\Laravel\Rendering\RenderedInvoice;

/**
 * Calculates invoices and chooses formats and profiles like a real run (requested formats,
 * defaults.format, the automatic choice, profile keys and objects, defaults.profile), but
 * skips validation, XML, template and PDF. Records every render call.
 */
final class QuittioFake extends QuittioManager
{
    /** @var list<RenderedInvoice> */
    private array $rendered = [];

    /** @var list<RenderedDocument> */
    private array $documents = [];

    /** Core without validators that takes the decisions of a run (see Quittio::prepare()). */
    private ?Quittio $planner = null;

    public function renderInvoice(InvoiceData $invoice, RenderOptions $options): RenderedInvoice
    {
        $prepared = $this->planner()->prepare($invoice, $options);
        $invoice = $prepared->data;

        $results = [];
        foreach ($prepared->formats as $key => $profile) {
            $format = $this->planner()->formats()->get($key);
            // Like a run: a profile for XML formats and for PDFs with an embedded XML (b2c_embed_xml).
            $profile = $format->syntax !== null || $format->pdf ? $profile : null;
            $results[$key] = new CoreRenderedInvoice(
                data: $invoice,
                format: $key,
                profile: $profile,
                validation: new ValidationResult,
                filenamePattern: 'Invoice_'.$invoice->number.'.{ext}',
                xml: $format->hasXml() || ($format->pdf && $profile !== null) ? '<?xml version="1.0" encoding="UTF-8"?><fake number="'.htmlspecialchars($invoice->number).'"/>' : null,
                pdf: $format->pdf ? "%PDF-1.7\n% fake invoice ".$invoice->number : null,
                html: $format->html ? '<p>'.htmlspecialchars($invoice->number).'</p>' : null,
                json: $format->json ? $invoice->toJson() : null,
            );
        }
        foreach ($results as $result) {
            $result->linkSiblings($results);
        }

        // The main result is the first format, as in a real run.
        $result = new RenderedInvoice($results[$prepared->format()] ?? throw new LogicException('The run chose no format.'));
        $this->rendered[] = $result;

        return $result;
    }

    public function renderDocument(DocumentData $document, RenderOptions $options): RenderedDocument
    {
        $document = (new DocumentCalculator)->calculate($document);
        // PendingDocument::htmlOnly() requests the format "html": no PDF, as in a real run.
        $pdf = in_array('html', $options->formats, true) ? null : "%PDF-1.7\n% fake document ".$document->number;
        $result = new RenderedDocument(new CoreRenderedDocument($document, '<p>'.htmlspecialchars($document->number).'</p>', $document->number.'.{ext}', $pdf));
        $this->documents[] = $result;

        return $result;
    }

    /**
     * @param  Closure(Container, array<string, mixed>): object  $factory
     */
    public function extend(string $type, string $name, Closure $factory): static
    {
        $this->planner = null;

        return parent::extend($type, $name, $factory);
    }

    public function registerCountry(CountryPack $pack): static
    {
        $this->planner?->registerCountry($pack);

        return parent::registerCountry($pack);
    }

    public function flush(): static
    {
        $this->planner = null;

        return parent::flush();
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

        Assert::assertNotEmpty($matching, $callback === null ? 'No document was rendered.' : 'No rendered document matches the given callback.');
    }

    /**
     * The recorded invoice results, in order (the main format of each call).
     *
     * @return list<RenderedInvoice>
     */
    public function rendered(): array
    {
        return $this->rendered;
    }

    /**
     * The recorded document results (quotes, reminders, …), in order.
     *
     * @return list<RenderedDocument>
     */
    public function documents(): array
    {
        return $this->documents;
    }

    private function planner(): Quittio
    {
        return $this->planner ??= $this->build()->withoutValidators();
    }
}
