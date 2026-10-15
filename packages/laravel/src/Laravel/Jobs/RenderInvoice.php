<?php

declare(strict_types=1);

namespace Quittio\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\FailOnException;
use JsonException;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Exceptions\HydrationException;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcasterChain;
use Quittio\Core\Serialization\Snapshots\SnapshotVersionException;
use Quittio\Core\Validation\ValidationException;
use Quittio\Core\View\MandatoryFieldMissing;
use Quittio\Laravel\Events\QueuedInvoiceFailed;
use Quittio\Laravel\Events\QueuedInvoiceStored;
use Quittio\Laravel\QuittioManager;
use Throwable;

/**
 * Renders an invoice in the queue and stores the main output on a disk.
 *
 * The invoice travels as JSON snapshot with its version, so jobs queued before an update of
 * the data model are brought up to date by the configured upcasters; the render options travel
 * as versioned JSON as well (RenderOptions::toArray()). Errors that another attempt cannot fix
 * (validation, invalid data, configuration) fail the job at once; others, e.g. a disk that is
 * not reachable, are retried.
 */
final class RenderInvoice implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** Exceptions that fail the job without a further attempt. */
    public const array PERMANENT_ERRORS = [
        ValidationException::class,
        InvalidInvoiceData::class,
        CalculationException::class,
        ConfigurationException::class,
        HydrationException::class,
        SnapshotVersionException::class,
        MandatoryFieldMissing::class,
        InvalidArgumentException::class,
        JsonException::class,
    ];

    public int $tries = 3;

    /** @var list<int> seconds before the second and the third attempt */
    public array $backoff = [10, 60];

    /**
     * JSON of RenderOptions::toArray(); a RenderOptions object in jobs queued by Quittio 0.1.0.
     */
    public RenderOptions|string $options;

    public function __construct(
        public readonly string $invoice,
        RenderOptions|string $options,
        public readonly ?string $disk = null,
        public readonly ?string $path = null,
    ) {
        $this->options = $options instanceof RenderOptions ? self::encode($options) : $options;
    }

    public function handle(QuittioManager $quittio, ?SnapshotUpcasterChain $upcasters = null): void
    {
        $result = $quittio->renderInvoice($this->data($upcasters), $this->renderOptions());
        $path = $result->save($this->disk, $this->path);

        Container::getInstance()->make(Dispatcher::class)->dispatch(new QueuedInvoiceStored($result, $this->disk, $path));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new FailOnException(self::PERMANENT_ERRORS)];
    }

    /**
     * Called by the queue worker when the job failed for good.
     */
    public function failed(Throwable $exception): void
    {
        Container::getInstance()->make(Dispatcher::class)->dispatch(new QueuedInvoiceFailed($this, $exception));
    }

    public function data(?SnapshotUpcasterChain $upcasters = null): InvoiceData
    {
        $snapshot = json_decode($this->invoice, true, flags: JSON_THROW_ON_ERROR);

        // Payloads without "_version" were queued as plain InvoiceData::toJson().
        return is_array($snapshot) && array_key_exists('_version', $snapshot)
            ? InvoiceData::fromSnapshot($snapshot, $upcasters)
            : InvoiceData::fromJson($this->invoice);
    }

    public function renderOptions(): RenderOptions
    {
        if ($this->options instanceof RenderOptions) {
            return $this->options;
        }

        $options = json_decode($this->options, true, flags: JSON_THROW_ON_ERROR);

        return RenderOptions::fromArray(is_array($options) ? $options : []);
    }

    private static function encode(RenderOptions $options): string
    {
        return json_encode($options->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
