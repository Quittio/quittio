<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Laravel\Jobs\RenderDocument;
use Quittio\Laravel\QuittioManager;

/**
 * Pending queued render of a document; dispatching happens on save().
 */
final readonly class QueuedDocument
{
    public function __construct(
        private QuittioManager $manager,
        private DocumentData $data,
        private RenderOptions $options,
        private ?string $connection = null,
        private ?string $queue = null,
    ) {}

    /**
     * Dispatches the job, which renders the document and stores it on $disk under $path
     * (defaults: quittio.storage.disk and the file name of the document).
     */
    public function save(?string $disk = null, ?string $path = null): RenderDocument
    {
        $config = Container::getInstance()->make(Repository::class);
        $connection = $this->connection ?? $config->get('quittio.queue.connection');
        $queue = $this->queue ?? $config->get('quittio.queue.queue');

        $job = RenderDocument::of($this->data, $this->options, $disk, $path);
        $job->onConnection(is_string($connection) ? $connection : null);
        $job->onQueue(is_string($queue) ? $queue : null);
        // Inside a database transaction: dispatch only after the commit.
        if ($config->get('quittio.queue.after_commit', true) !== false) {
            $job->afterCommit();
        }

        return $this->manager->queueDocument($job);
    }
}
