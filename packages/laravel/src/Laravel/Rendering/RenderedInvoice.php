<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use Illuminate\Contracts\Mail\Attachable;
use Illuminate\Contracts\Support\Responsable;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Rendering\RenderedInvoice as CoreRenderedInvoice;
use Quittio\Core\Validation\ValidationResult;

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
