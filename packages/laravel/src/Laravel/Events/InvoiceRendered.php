<?php

declare(strict_types=1);

namespace Quittio\Laravel\Events;

use Quittio\Laravel\Rendering\RenderedInvoice;

/**
 * Dispatched after all requested formats were produced.
 */
final readonly class InvoiceRendered
{
    public function __construct(
        public RenderedInvoice $result,
    ) {}
}
