<?php

declare(strict_types=1);

namespace Quittio\Laravel\Events;

use Quittio\Laravel\Rendering\RenderedDocument;

/**
 * Dispatched by a queued RenderDocument job after it stored the document. DocumentRendered
 * precedes it.
 */
final readonly class QueuedDocumentStored
{
    /**
     * @param  string|null  $disk  the disk of the job; null = quittio.storage.disk or the default disk
     * @param  string  $path  the stored path
     */
    public function __construct(
        public RenderedDocument $result,
        public ?string $disk,
        public string $path,
    ) {}
}
