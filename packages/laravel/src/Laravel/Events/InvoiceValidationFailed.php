<?php

declare(strict_types=1);

namespace Quittio\Laravel\Events;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Validation\ValidationResult;

/**
 * Dispatched when validation blocked rendering.
 */
final readonly class InvoiceValidationFailed
{
    public function __construct(
        public InvoiceData $invoice,
        public ValidationResult $result,
    ) {}
}
