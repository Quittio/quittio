<?php

declare(strict_types=1);

use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Exceptions\HydrationException;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Testing\Scenarios;
use Quittio\Laravel\Events\QueuedInvoiceFailed;
use Quittio\Laravel\Events\QueuedInvoiceStored;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Jobs\RenderInvoice;
use Quittio\Laravel\QuittioManager;

function queuedJob(): RenderInvoice
{
    Queue::fake();
    Quittio::make(Scenarios::make('simple')->build())
        ->format('zugferd')
        ->profile(Quittio::profiles()->get('en16931'))
        ->template('default')
        ->locale('en')
        ->presentation(new Presentation(showItemNumbers: true, paper: 'Letter'))
        ->option('fold_marks', false)
        ->queue()
        ->save('invoices', 'queued/');

    $jobs = Queue::pushed(RenderInvoice::class);

    return $jobs->first() ?? throw new LogicException('No job was pushed.');
}

/**
 * Runs the job with its middleware, like a queue worker.
 */
function runJob(RenderInvoice $job): void
{
    (new Pipeline(app()))->send($job)->through($job->middleware())->then(static fn (RenderInvoice $job) => $job->handle(app(QuittioManager::class)));
}

it('queues the render options as versioned JSON and restores them', function () {
    $job = queuedJob();
    $restored = unserialize(serialize($job));

    expect($job->options)->toBeString()
        ->and(json_decode((string) $job->options, true)['_version'])->toBe(RenderOptions::ARRAY_VERSION)
        ->and($restored->renderOptions()->profile)->toBe('en16931')
        ->and($restored->renderOptions()->template)->toBe('default')
        ->and($restored->renderOptions()->locale)->toBe('en')
        ->and($restored->renderOptions()->formats)->toBe(['zugferd'])
        ->and($restored->renderOptions()->options)->toBe(['fold_marks' => false])
        ->and($restored->renderOptions()->presentation?->showItemNumbers)->toBeTrue()
        ->and($restored->renderOptions()->presentation?->paper)->toBe('Letter');
});

it('renders jobs queued with a RenderOptions object by Quittio 0.1.0', function () {
    Storage::fake('invoices');
    // The properties as Quittio 0.1.0 serialised them.
    $job = (new ReflectionClass(RenderInvoice::class))->newInstanceWithoutConstructor();
    Closure::bind(function (): void {
        $this->invoice = json_encode(Scenarios::make('simple')->build()->toSnapshot(), JSON_THROW_ON_ERROR);
        $this->options = new RenderOptions(formats: ['cii']);
        $this->disk = 'invoices';
        $this->path = 'old/';
    }, $job, RenderInvoice::class)();

    $job->handle(app(QuittioManager::class));

    Storage::disk('invoices')->assertExists('old/Rechnung_RE-2026-0042.xml');
});

it('refuses render options that cannot be stored or read', function () {
    expect(fn () => (new RenderOptions(options: ['logger' => new stdClass]))->toArray())->toThrow(InvalidArgumentException::class, 'logger')
        ->and(fn () => RenderOptions::fromArray(['_version' => 99]))->toThrow(InvalidArgumentException::class, 'version 99')
        ->and(fn () => RenderOptions::fromArray(['_version' => 1, 'formats' => [1]]))->toThrow(InvalidArgumentException::class, 'formats');
});

it('reports the stored file', function () {
    Storage::fake('invoices');
    Event::fake([QueuedInvoiceStored::class]);

    queuedJob()->handle(app(QuittioManager::class));

    Event::assertDispatched(QueuedInvoiceStored::class, fn (QueuedInvoiceStored $event): bool => $event->disk === 'invoices'
        && $event->path === 'queued/Invoice_RE-2026-0042.pdf'
        && $event->result->data()->number === 'RE-2026-0042');
});

it('fails at once on errors that another attempt cannot fix', function () {
    $job = (new RenderInvoice('{}', new RenderOptions(formats: ['cii'])))->withFakeQueueInteractions();

    expect(fn () => runJob($job))->toThrow(HydrationException::class);
    $job->assertFailed();
});

it('retries errors that may pass', function () {
    // A directory in the place of the file: the disk refuses the write.
    $root = sys_get_temp_dir().'/quittio-disk-'.bin2hex(random_bytes(4));
    mkdir($root.'/x.xml', 0775, true);
    config()->set('filesystems.disks.broken', ['driver' => 'local', 'root' => $root, 'throw' => false]);
    $job = (new RenderInvoice(json_encode(Scenarios::make('simple')->build()->toSnapshot(), JSON_THROW_ON_ERROR), new RenderOptions(formats: ['cii']), 'broken', 'x.xml'))->withFakeQueueInteractions();

    try {
        expect(fn () => runJob($job))->toThrow(RuntimeException::class, 'Unable to store');
    } finally {
        rmdir($root.'/x.xml');
        rmdir($root);
    }
    $job->assertNotFailed();
});

it('dispatches an event when the job failed for good', function () {
    Event::fake([QueuedInvoiceFailed::class]);
    $job = new RenderInvoice('{}', new RenderOptions);
    $exception = new RuntimeException('disk unreachable');

    $job->failed($exception);

    Event::assertDispatched(QueuedInvoiceFailed::class, fn (QueuedInvoiceFailed $event): bool => $event->job === $job && $event->exception === $exception);
});

it('dispatches the job after the commit of a database transaction', function () {
    expect(queuedJob()->afterCommit)->toBeTrue();

    config()->set('quittio.queue.after_commit', false);
    expect(queuedJob()->afterCommit)->toBeNull();
});
