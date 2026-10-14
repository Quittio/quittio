<?php

declare(strict_types=1);

namespace Quittio\Laravel\Events;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Validation\ValidationResult;

/**
 * Dispatched after a render run whose validation did not block it, before InvoiceRendered.
 * $result is the validation of the main (first) format: in mode "error" it has no errors
 * (warnings may be present unless validation.block_on_warnings), in mode "warn" it may contain
 * errors, so check $result->isValid(). Not dispatched in mode "off" (nothing was validated) and
 * not when validation blocked the run (see InvoiceValidationFailed).
 */
final readonly class InvoiceValidated
{
    public function __construct(
        public InvoiceData $invoice,
        public ValidationResult $result,
    ) {}
}
