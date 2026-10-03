<?php

declare(strict_types=1);

namespace Quittio\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Laravel\QuittioManager;

/**
 * Renders an invoice in the queue and stores the main output on a disk.
 * The invoice travels as JSON snapshot, so the job payload is stable and readable.
 */
final class RenderInvoice implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $invoice,
        public readonly RenderOptions $options,
        public readonly ?string $disk = null,
        public readonly ?string $path = null,
    ) {}

    public function handle(QuittioManager $quittio): void
    {
        $quittio->renderInvoice(InvoiceData::fromJson($this->invoice), $this->options)->save($this->disk, $this->path);
    }
}
