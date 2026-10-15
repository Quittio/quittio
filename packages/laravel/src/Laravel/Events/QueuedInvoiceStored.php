<?php

declare(strict_types=1);

namespace Quittio\Laravel\Events;

use Quittio\Laravel\Rendering\RenderedInvoice;

/**
 * Dispatched by a queued RenderInvoice job after it stored the main output, e.g. to link the
 * stored file to an order. InvoiceRendered precedes it.
 */
final readonly class QueuedInvoiceStored
{
    /**
     * @param  string|null  $disk  the disk of the job; null = quittio.storage.disk or the default disk
     * @param  string  $path  the stored path
     */
    public function __construct(
        public RenderedInvoice $result,
        public ?string $disk,
        public string $path,
    ) {}
}
