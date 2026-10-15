<?php

declare(strict_types=1);

namespace Quittio\Laravel\Events;

use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Rendering\RenderOptions;

/**
 * Dispatched before a quote, order confirmation, delivery note, proforma invoice, payment
 * reminder or account statement is calculated and rendered.
 */
final readonly class DocumentRendering
{
    public function __construct(
        public DocumentData $document,
        public RenderOptions $options,
    ) {}
}
