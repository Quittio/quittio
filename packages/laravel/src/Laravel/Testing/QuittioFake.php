<?php

declare(strict_types=1);

namespace Quittio\Laravel\Testing;

use PHPUnit\Framework\Assert;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Documents\DocumentCalculator;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Exceptions\LogicException;
use Quittio\Core\Quittio;
use Quittio\Core\Rendering\RenderedDocument as CoreRenderedDocument;
use Quittio\Core\Rendering\RenderedInvoice as CoreRenderedInvoice;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Laravel\Jobs\RenderDocument;
use Quittio\Laravel\Jobs\RenderInvoice;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Rendering\RenderedDocument;
use Quittio\Laravel\Rendering\RenderedInvoice;

/**
 * Calculates invoices and chooses formats and profiles like a real run (requested formats,
 * defaults.format, the automatic choice, profile keys and objects, defaults.profile), but
 * skips validation, XML, template and PDF. Records every render call and every queued render,
 * and dispatches the events of a real run (InvoiceRendering, InvoiceValidated with an empty
 * result unless validation is off, InvoiceRendered).
 */
final class QuittioFake extends QuittioManager
{
    /** @var list<RenderedInvoice> */
    private array $rendered = [];

    /** @var list<RenderedDocument> */
    private array $documents = [];

    /** @var list<RenderInvoice> */
    private array $queued = [];

    /** @var list<RenderDocument> */
    private array $queuedDocuments = [];

    /** Core without validators that takes the decisions of a run (see Quittio::prepare()). */
    private ?Quittio $planner = null;

    /** The core the planner was copied from; a new core (other configuration) gets a new planner. */
    private ?Quittio $plannerSource = null;

    /**
     * Records the job and dispatches it like the real manager, so Queue::fake() sees it and a
     * sync queue renders it with the fake.
     */
    public function queueInvoice(RenderInvoice $job): RenderInvoice
    {
        $this->queued[] = $job;

        return parent::queueInvoice($job);
    }

    /**
     * Records the document job and dispatches it like the real manager.
     */
    public function queueDocument(RenderDocument $job): RenderDocument
    {
        $this->queuedDocuments[] = $job;

        return parent::queueDocument($job);
    }

    protected function run(InvoiceData $invoice, RenderOptions $options): RenderedInvoice
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

    protected function runDocument(DocumentData $document, RenderOptions $options): RenderedDocument
    {
        $document = (new DocumentCalculator($this->core()->calculator()))->calculate($document);
        // PendingDocument::htmlOnly() requests the format "html": no PDF, as in a real run.
        $pdf = in_array('html', $options->formats, true) ? null : "%PDF-1.7\n% fake document ".$document->number;
        $result = new RenderedDocument(new CoreRenderedDocument($document, '<p>'.htmlspecialchars($document->number).'</p>', $document->number.'.{ext}', $pdf));
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

    /**
     * Asserts that exactly $count invoices were rendered (documents are not counted); fails with
     * the actual number otherwise.
     */
    public function assertRenderedCount(int $count): void
    {
        Assert::assertCount($count, $this->rendered, sprintf('Expected %d rendered invoices, got %d.', $count, count($this->rendered)));
    }

    /**
     * Asserts that no invoice was rendered (documents are not counted); fails with the number of
     * rendered invoices otherwise.
     */
    public function assertNothingRendered(): void
    {
        Assert::assertSame([], $this->rendered, sprintf('Expected no rendered invoice, got %d.', count($this->rendered)));
    }

    /**
     * @param  (callable(RenderInvoice): bool)|null  $callback  e.g. fn ($job) => $job->data()->number === 'RE-1'
     */
    public function assertQueued(?callable $callback = null): void
    {
        $matching = $callback === null ? $this->queued : array_filter($this->queued, $callback);

        Assert::assertNotEmpty($matching, $callback === null ? 'No invoice was queued.' : 'No queued invoice matches the given callback.');
    }

    /**
     * Asserts that neither an invoice nor a document was queued.
     */
    public function assertNothingQueued(): void
    {
        Assert::assertSame([], $this->queued, sprintf('Expected no queued invoice, got %d.', count($this->queued)));
        Assert::assertSame([], $this->queuedDocuments, sprintf('Expected no queued document, got %d.', count($this->queuedDocuments)));
    }

    /**
     * @param  (callable(RenderDocument): bool)|null  $callback  e.g. fn ($job) => $job->data()->number === 'AN-1'
     */
    public function assertDocumentQueued(?callable $callback = null): void
    {
        $matching = $callback === null ? $this->queuedDocuments : array_filter($this->queuedDocuments, $callback);

        Assert::assertNotEmpty($matching, $callback === null ? 'No document was queued.' : 'No queued document matches the given callback.');
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

    /**
     * The recorded queued render jobs, in order.
     *
     * @return list<RenderInvoice>
     */
    public function queued(): array
    {
        return $this->queued;
    }

    /**
     * The recorded queued render jobs of documents, in order.
     *
     * @return list<RenderDocument>
     */
    public function queuedDocuments(): array
    {
        return $this->queuedDocuments;
    }

    private function planner(): Quittio
    {
        $core = $this->core();
        if ($this->planner === null || $this->plannerSource !== $core) {
            $this->planner = (clone $core)->clearValidators();
            $this->plannerSource = $core;
        }

        return $this->planner;
    }
}
