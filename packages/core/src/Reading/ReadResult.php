<?php

declare(strict_types=1);

namespace Quittio\Core\Reading;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Totals\Totals;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Validation\ValidationMessage;

/**
 * Result of reading an e-invoice: the parsed data, the totals declared in the document
 * (kept unchanged for comparison), findings and the raw input.
 */
final readonly class ReadResult
{
    /**
     * @param  list<VatBreakdown>  $declaredVatBreakdown
     * @param  list<ValidationMessage>  $findings
     */
    public function __construct(
        public InvoiceData $data,
        public string $syntax,
        public ?string $specificationId,
        public ?string $profile,
        public ?Totals $declaredTotals,
        public array $declaredVatBreakdown,
        public array $findings,
        public string $xml,
        public ?string $raw = null,
        public ?string $embeddedFilename = null,
    ) {}

    public function hasFindings(): bool
    {
        return $this->findings !== [];
    }
}
