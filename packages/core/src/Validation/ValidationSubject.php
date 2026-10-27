<?php

declare(strict_types=1);

namespace Quittio\Core\Validation;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Profiles\Syntax;

/**
 * What is validated: the calculated data and, where available, the generated XML and PDF.
 */
final readonly class ValidationSubject
{
    public function __construct(
        public InvoiceData $invoice,
        public ValidationContext $context,
        public ?string $xml = null,
        public ?Syntax $syntax = null,
        public ?string $pdf = null,
    ) {}

    /**
     * Returns a copy with the generated XML and its syntax, which the XML drivers (XSD, KoSIT)
     * check.
     */
    public function withXml(string $xml, Syntax $syntax): self
    {
        return new self($this->invoice, $this->context, $xml, $syntax, $this->pdf);
    }

    /**
     * Returns a copy with the generated PDF, which the PDF/A driver (veraPDF) checks.
     */
    public function withPdf(string $pdf): self
    {
        return new self($this->invoice, $this->context, $this->xml, $this->syntax, $pdf);
    }
}
