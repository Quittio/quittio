<?php

declare(strict_types=1);

use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\HydrationException;
use Quittio\Laravel\Events\DocumentRendered;
use Quittio\Laravel\Events\QueuedDocumentFailed;
use Quittio\Laravel\Events\QueuedDocumentStored;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Jobs\RenderDocument;
use Quittio\Laravel\QuittioManager;

function queuedQuote(): DocumentData
{
    return DocumentBuilder::quote()->number('AN-2026-0031')->issueDate('2026-10-01')->validUntil('2026-10-31')
        ->seller(fn ($p) => $p->name('Muster GmbH')->vatId('DE123456789')->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE'))
        ->buyer(fn ($p) => $p->name('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->line(fn ($l) => $l->name('Beratung')->quantity(10, 'HUR')->netPrice('95.00')->vat(VatCategory::StandardRate, '19'))
        ->optionalLine(fn ($l) => $l->name('Schulung')->quantity(1, 'DAY')->netPrice('850.00')->vat(VatCategory::StandardRate, '19'))
        ->build();
}

function queuedDocumentJob(): RenderDocument
{
    Queue::fake();
    Quittio::document(queuedQuote())->locale('de')->option('fold_marks', false)->queue()->save('documents', 'quotes/');

    return Queue::pushed(RenderDocument::class)->first() ?? throw new LogicException('No job was pushed.');
}

function runDocumentJob(RenderDocument $job): void
{
    (new Pipeline(app()))->send($job)->through($job->middleware())->then(static fn (RenderDocument $job) => $job->handle(app(QuittioManager::class)));
}

it('queues the document as versioned snapshot without PHP serialisation', function () {
    $job = queuedDocumentJob();
    $restored = unserialize(serialize($job));

    expect(json_decode($job->document, true)['_version'])->toBe(DocumentData::SNAPSHOT_VERSION)
        ->and($job->document)->not->toContain('O:')
        ->and($restored->data()->number)->toBe('AN-2026-0031')
        ->and($restored->data()->options('2')->optional)->toBeTrue()
        ->and($restored->renderOptions()->locale)->toBe('de')
        ->and($restored->renderOptions()->options)->toBe(['fold_marks' => false])
        ->and($job->disk)->toBe('documents');
});

it('renders and stores a queued document', function () {
    Storage::fake('documents');
    Event::fake([DocumentRendered::class, QueuedDocumentStored::class]);
    $job = queuedDocumentJob();

    runDocumentJob($job);

    Event::assertDispatched(DocumentRendered::class);
    Event::assertDispatched(QueuedDocumentStored::class, fn (QueuedDocumentStored $e) => $e->disk === 'documents' && Storage::disk('documents')->exists($e->path) && str_starts_with($e->path, 'quotes/'));
});

it('fails a job with a broken payload at once and reports it', function () {
    Event::fake([QueuedDocumentFailed::class]);
    $job = new RenderDocument('{"_version": 1, "type": "quote"}', '{"_version": 1}');

    expect(fn () => runDocumentJob($job))->toThrow(HydrationException::class);
    $job->failed(new RuntimeException('gone'));
    Event::assertDispatched(QueuedDocumentFailed::class, fn (QueuedDocumentFailed $e) => $e->job === $job);
});

it('records queued documents in the fake', function () {
    $fake = Quittio::fake();
    Queue::fake();

    $fake->assertNothingQueued();
    Quittio::document(queuedQuote())->queue()->save();

    $fake->assertDocumentQueued(fn (RenderDocument $job) => $job->data()->number === 'AN-2026-0031');
    expect($fake->queuedDocuments())->toHaveCount(1)
        ->and(fn () => $fake->assertNothingQueued())->toThrow(PHPUnit\Framework\AssertionFailedError::class);
});
