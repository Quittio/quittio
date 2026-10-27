<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Laravel\Jobs\RenderInvoice;
use Quittio\Laravel\QuittioManager;

/**
 * Pending queued render; dispatching happens on save().
 */
final readonly class QueuedInvoice
{
    public function __construct(
        private QuittioManager $manager,
        private InvoiceData $data,
        private RenderOptions $options,
        private ?string $connection = null,
        private ?string $queue = null,
    ) {}

    /**
     * Dispatches a RenderInvoice job that renders the invoice and stores the main output on $disk
     * (default: quittio.storage.disk) at $path (default: the file name; a path ending in "/" gets
     * the file name appended). Uses the connection and queue given to queue(), else those of
     * quittio.queue; inside a database transaction the job is dispatched after the commit unless
     * quittio.queue.after_commit is false. Returns the dispatched job.
     */
    public function save(?string $disk = null, ?string $path = null): RenderInvoice
    {
        $container = Container::getInstance();
        $config = $container->make(Repository::class);
        $connection = $this->connection ?? $config->get('quittio.queue.connection');
        $queue = $this->queue ?? $config->get('quittio.queue.queue');

        $job = new RenderInvoice(json_encode($this->data->toSnapshot(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->options, $disk, $path);
        $job->onConnection(is_string($connection) ? $connection : null);
        $job->onQueue(is_string($queue) ? $queue : null);
        // Inside a database transaction: dispatch only after the commit, so that a rollback does
        // not leave a rendered invoice behind.
        if ($config->get('quittio.queue.after_commit', true) !== false) {
            $job->afterCommit();
        }

        return $this->manager->queueInvoice($job);
    }
}
