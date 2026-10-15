<?php

declare(strict_types=1);

namespace Quittio\Laravel\Events;

use Quittio\Laravel\Rendering\RenderedDocument;

/**
 * Dispatched after a document (quote, reminder, …) was rendered.
 */
final readonly class DocumentRendered
{
    public function __construct(
        public RenderedDocument $result,
    ) {}
}
