<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Testing\Scenarios;
use Quittio\Laravel\Facades\Quittio;
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
