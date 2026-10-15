<?php

declare(strict_types=1);

namespace Quittio\Laravel\Events;

use Quittio\Laravel\Jobs\RenderInvoice;
use Throwable;

/**
 * Dispatched when a queued RenderInvoice job failed for good: after its last attempt, or at
 * once for errors that another attempt cannot fix (validation, invalid data, configuration).
 * $job->data() returns the invoice.
 */
final readonly class QueuedInvoiceFailed
{
    public function __construct(
        public RenderInvoice $job,
        public Throwable $exception,
    ) {}
}
