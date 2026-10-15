<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Mail\Attachable;
use Illuminate\Contracts\Support\Responsable;
use Quittio\Core\Corrections\CancellationHistory;
use Quittio\Core\Data\InvoiceData;
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

    public function data(): InvoiceData
    {
        return $this->result->data();
    }

    public function format(): string
    {
        return $this->result->format();
    }

    public function profile(): ?Profile
    {
        return $this->result->profile();
    }

    public function validation(): ValidationResult
    {
        return $this->result->validation();
    }

    public function hasPdf(): bool
    {
        return $this->result->hasPdf();
    }

    public function hasXml(): bool
    {
        return $this->result->hasXml();
    }

    public function pdf(): string
    {
        return $this->result->pdf();
    }

    public function xml(): string
    {
        return $this->result->xml();
    }

    public function html(): string
    {
        return $this->result->html();
    }

    public function json(): string
    {
        return $this->result->json();
    }

    public function contents(): string
    {
        return $this->result->contents();
    }

    public function mimeType(): string
    {
        return $this->result->mimeType();
    }

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
