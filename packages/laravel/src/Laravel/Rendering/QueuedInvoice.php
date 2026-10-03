<?php

declare(strict_types=1);

namespace Quittio\Laravel\Rendering;

use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Laravel\Jobs\RenderInvoice;

/**
 * Pending queued render; dispatching happens on save().
 */
final readonly class QueuedInvoice
{
    public function __construct(
        private InvoiceData $data,
        private RenderOptions $options,
        private ?string $connection = null,
        private ?string $queue = null,
    ) {}

    public function save(?string $disk = null, ?string $path = null): RenderInvoice
    {
        $container = Container::getInstance();
        $config = $container->make(Repository::class);
        $connection = $this->connection ?? $config->get('quittio.queue.connection');
        $queue = $this->queue ?? $config->get('quittio.queue.queue');

        $job = new RenderInvoice($this->data->toJson(), $this->options, $disk, $path);
        $job->onConnection(is_string($connection) ? $connection : null);
        $job->onQueue(is_string($queue) ? $queue : null);

        $container->make(Dispatcher::class)->dispatch($job);

        return $job;
    }
}
