<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Testing\Scenarios;
use Quittio\Laravel\Events\InvoiceRendered;
use Quittio\Laravel\Events\InvoiceRendering;
use Quittio\Laravel\Events\InvoiceValidated;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Jobs\RenderInvoice;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Testing\InteractsWithQuittio;

uses(InteractsWithQuittio::class);

it('asserts valid e-invoices with the expected profile', function () {
    $result = Quittio::make(Scenarios::make('simple')->buyerReference('04011000-12345-03')->build())->formats(['zugferd', 'xrechnung-ubl'])->render();

    $this->assertValidEInvoice($result->pdf(), 'en16931');
    $this->assertValidEInvoice($result->as('xrechnung-ubl')->xml(), 'xrechnung');
});

it('fails for invalid e-invoices', function () {
    $xml = Quittio::make(Scenarios::make('simple')->build())->format('cii')->render()->xml();

    $this->assertValidEInvoice(str_replace('<ram:TypeCode>380</ram:TypeCode>', '<ram:TypeCode>999</ram:TypeCode>', $xml));
})->throws(AssertionFailedError::class);

it('compares XML with snapshots', function () {
    $xml = Quittio::make(InvoiceData::factory()->withLines(2)->make())->format('cii')->render()->xml();
    $file = __DIR__.'/__snapshots__/quittio/factory-cii.xml';
    @unlink($file);

    $this->assertXmlMatchesSnapshot($xml, 'factory-cii');
    $this->assertXmlMatchesSnapshot($xml, 'factory-cii');
    expect(fn () => $this->assertXmlMatchesSnapshot(str_replace('RE-2026-0042', 'RE-X', $xml), 'factory-cii'))->toThrow(AssertionFailedError::class);
    unlink($file);
});

it('dispatches the events of a real run with the fake', function () {
    Event::fake();
    Quittio::fake();

    Quittio::make(Scenarios::make('simple')->build())->format('cii')->render();

    Event::assertDispatched(InvoiceRendering::class);
    Event::assertDispatched(InvoiceValidated::class, fn (InvoiceValidated $event): bool => $event->result->isValid());
    Event::assertDispatched(InvoiceRendered::class, fn (InvoiceRendered $event): bool => $event->result->data()->number === 'RE-2026-0042');

    // Mode "off" validates nothing, as in a real run.
    Quittio::make(Scenarios::make('simple')->build())->format('cii')->withoutValidation()->render();
    Event::assertDispatchedTimes(InvoiceValidated::class, 1);
});

it('records queued invoices with the fake and renders them with it', function () {
    Queue::fake();
    $fake = Quittio::fake();
    Quittio::assertNothingQueued();

    Quittio::make(Scenarios::make('simple')->build())->format('cii')->queue()->save('invoices', 'queued/');

    Quittio::assertQueued(fn (RenderInvoice $job): bool => $job->data()->number === 'RE-2026-0042' && $job->path === 'queued/');
    Queue::assertPushed(RenderInvoice::class);
    expect($fake->queued())->toHaveCount(1);

    Storage::fake('invoices');
    $fake->queued()[0]->handle(app(QuittioManager::class));
    Quittio::assertRenderedCount(1);
    expect(fn () => Quittio::assertQueued(fn (RenderInvoice $job): bool => $job->path === 'other/'))->toThrow(AssertionFailedError::class);
});
