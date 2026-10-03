<?php

declare(strict_types=1);

namespace Quittio\Laravel\Events;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Rendering\RenderOptions;

/**
 * Dispatched before an invoice is calculated, validated and rendered.
 */
final readonly class InvoiceRendering
{
    public function __construct(
        public InvoiceData $invoice,
        public RenderOptions $options,
    ) {}
}
