<?php

declare(strict_types=1);

namespace Quittio\Laravel\Events;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Validation\ValidationResult;

/**
 * Dispatched when an invoice passed validation (warnings may be present).
 */
final readonly class InvoiceValidated
{
    public function __construct(
        public InvoiceData $invoice,
        public ValidationResult $result,
    ) {}
}
