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
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Exceptions\HydrationException;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Serialization\Snapshots\SnapshotVersionException;
use Quittio\Laravel\Events\QueuedDocumentFailed;
use Quittio\Laravel\Events\QueuedDocumentStored;
use Quittio\Laravel\QuittioManager;
use Throwable;

/**
 * Renders a quote, delivery note, reminder or other document in the queue and stores it on a
 * disk. The document travels as versioned JSON snapshot (DocumentData::toSnapshot(), brought up
 * to date by quittio.snapshots.document_upcasters), the render options as versioned JSON.
 * Errors that another attempt cannot fix fail the job at once; others are retried.
 */
final class RenderDocument implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** Exceptions that fail the job without a further attempt. */
    public const array PERMANENT_ERRORS = [
        CalculationException::class,
        ConfigurationException::class,
        HydrationException::class,
        SnapshotVersionException::class,
        InvalidArgumentException::class,
        JsonException::class,
    ];

    public int $tries = 3;

    /** @var list<int> seconds before the second and the third attempt */
    public array $backoff = [10, 60];

    /**
     * @param  string  $document  JSON of DocumentData::toSnapshot()
     * @param  string  $options  JSON of RenderOptions::toArray()
     */
    public function __construct(
        public readonly string $document,
        public readonly string $options,
        public readonly ?string $disk = null,
        public readonly ?string $path = null,
    ) {}

    /**
     * The job of a document and its render options.
     */
    public static function of(DocumentData $document, RenderOptions $options, ?string $disk = null, ?string $path = null): self
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        return new self(json_encode($document->toSnapshot(), $flags), json_encode($options->toArray(), $flags), $disk, $path);
    }

    /**
     * Renders the document with the events of a render run, stores it and dispatches QueuedDocumentStored.
     */
    public function handle(QuittioManager $quittio): void
    {
        $result = $quittio->renderDocument($this->data(), $this->renderOptions());
        $path = $result->save($this->disk, $this->path);

        Container::getInstance()->make(Dispatcher::class)->dispatch(new QueuedDocumentStored($result, $this->disk, $path));
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
        Container::getInstance()->make(Dispatcher::class)->dispatch(new QueuedDocumentFailed($this, $exception));
    }

    /**
     * The document of the job, upcast with the document upcasters of the application.
     *
     * @throws JsonException|HydrationException|SnapshotVersionException for a payload that cannot be read
     */
    public function data(): DocumentData
    {
        $snapshot = json_decode($this->document, true, flags: JSON_THROW_ON_ERROR);

        return DocumentData::fromSnapshot(is_array($snapshot) ? $snapshot : []);
    }

    /**
     * The render options of the job.
     *
     * @throws JsonException for a payload that cannot be read
     */
    public function renderOptions(): RenderOptions
    {
        $options = json_decode($this->options, true, flags: JSON_THROW_ON_ERROR);

        return RenderOptions::fromArray(is_array($options) ? $options : []);
    }
}
