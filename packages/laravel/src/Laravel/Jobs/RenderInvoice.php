<?php

declare(strict_types=1);

namespace Quittio\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcasterChain;
use Quittio\Laravel\QuittioManager;

/**
 * Renders an invoice in the queue and stores the main output on a disk.
 * The invoice travels as JSON snapshot with its version, so jobs queued before an update
 * of the data model are brought up to date by the configured upcasters.
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

    public function handle(QuittioManager $quittio, ?SnapshotUpcasterChain $upcasters = null): void
    {
        $quittio->renderInvoice($this->data($upcasters), $this->options)->save($this->disk, $this->path);
    }

    public function data(?SnapshotUpcasterChain $upcasters = null): InvoiceData
    {
        $snapshot = json_decode($this->invoice, true, flags: JSON_THROW_ON_ERROR);

        // Payloads without "_version" were queued as plain InvoiceData::toJson().
        return is_array($snapshot) && array_key_exists('_version', $snapshot)
            ? InvoiceData::fromSnapshot($snapshot, $upcasters)
            : InvoiceData::fromJson($this->invoice);
    }
}
