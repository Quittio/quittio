<?php

declare(strict_types=1);

namespace Quittio\Laravel\Events;

use Quittio\Laravel\Jobs\RenderDocument;
use Throwable;

/**
 * Dispatched when a queued RenderDocument job failed for good: after its last attempt, or at
 * once for errors that another attempt cannot fix. $job->data() returns the document.
 */
final readonly class QueuedDocumentFailed
{
    public function __construct(
        public RenderDocument $job,
        public Throwable $exception,
    ) {}
}
