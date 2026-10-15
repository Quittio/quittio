<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Mail\Attachable;
use Illuminate\Contracts\Support\Responsable;
use Quittio\Core\Corrections\CancellationHistory;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Exceptions\LogicException;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Rendering\RenderedInvoice as CoreRenderedInvoice;
use Quittio\Core\Support\Date;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Laravel\QuittioManager;

/**
 * Rendered invoice for Laravel: usable as mail attachment and as
 * HTTP response, storable on any filesystem disk.
 */
final class RenderedInvoice implements Attachable, Responsable
{
    use DeliversFile;

    public function __construct(
        private readonly CoreRenderedInvoice $result,
    ) {}

    /**
     * The result of the core, e.g. for save() to a local path.
     */
    public function core(): CoreRenderedInvoice
    {
        return $this->result;
    }

    /**
     * Cancels this invoice: builds the cancellation with Quittio::cancellation() and renders it
     * in the formats of this invoice. $lines cancels part of it, $history passes the earlier
     * partial cancellations (see Quittio::cancellation()).
     *
     * @param  list<array{line: string, quantity?: \Brick\Math\BigDecimal|int|string, amount?: string}>|null  $lines
     */
    public function cancel(string $number, ?string $reason = null, ?array $lines = null, Date|DateTimeInterface|string|null $issueDate = null, ?string $strategy = null, ?CancellationHistory $history = null): self
    {
        $quittio = Container::getInstance()->make(QuittioManager::class);
        $cancellation = $quittio->cancellation($this->data(), $number, $reason, $strategy, $issueDate === null ? null : Date::parse($issueDate), $lines, $history);

        return $quittio->make($cancellation)->formats($this->formats())->render();
    }

    /**
     * The result for another format of the same run.
     *
     * @throws LogicException when the format was not rendered in this run
     */
    public function as(string $format): self
    {
        return new self($this->result->as($format));
    }

    /**
     * @return list<string>
     */
    public function formats(): array
    {
        return $this->result->formats();
    }

    /**
     * The calculated invoice data of the run (totals, VAT breakdown, due date).
     */
    public function data(): InvoiceData
    {
        return $this->result->data();
    }

    /**
     * Key of the format of this result, e.g. "zugferd".
     */
    public function format(): string
    {
        return $this->result->format();
    }

    /**
     * Profile of the XML; null for formats without XML.
     */
    public function profile(): ?Profile
    {
        return $this->result->profile();
    }

    /**
     * Validation result of this format; in the mode "warn" it may contain errors, empty in the
     * mode "off".
     */
    public function validation(): ValidationResult
    {
        return $this->result->validation();
    }

    /**
     * Whether this format produced a PDF.
     */
    public function hasPdf(): bool
    {
        return $this->result->hasPdf();
    }

    /**
     * Whether this format produced XML (also the XML embedded in a PDF).
     */
    public function hasXml(): bool
    {
        return $this->result->hasXml();
    }

    /**
     * The PDF.
     *
     * @throws LogicException when the format produces no PDF (see hasPdf())
     */
    public function pdf(): string
    {
        return $this->result->pdf();
    }

    /**
     * The e-invoice XML, also the one embedded in the PDF.
     *
     * @throws LogicException when the format produces no XML (see hasXml())
     */
    public function xml(): string
    {
        return $this->result->xml();
    }

    /**
     * The rendered template, as mPDF received it.
     *
     * @throws LogicException when the format produced no HTML
     */
    public function html(): string
    {
        return $this->result->html();
    }

    /**
     * The invoice as JSON (InvoiceData::toJson()).
     */
    public function json(): string
    {
        return $this->result->json();
    }

    /**
     * The main output: PDF, otherwise XML, HTML or JSON.
     */
    public function contents(): string
    {
        return $this->result->contents();
    }

    /**
     * MIME type of the main output (contents()).
     */
    public function mimeType(): string
    {
        return $this->result->mimeType();
    }

    /**
     * File name of the main output, from the configured pattern; $extension replaces the
     * extension of the main output.
     */
    public function filename(?string $extension = null): string
    {
        return $this->result->filename($extension);
    }

    /**
     * @return array<string, string>
     */
    public function hashes(): array
    {
        return $this->result->hashes();
    }
}
