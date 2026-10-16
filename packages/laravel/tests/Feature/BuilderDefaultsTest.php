<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Line;
use Quittio\Core\Countries\CountryGeneric;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Testing\Scenarios;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Seller;

function invoiceWithoutVat(): InvoiceBuilder
{
    return Seller::apply(InvoiceBuilder::make())
        ->number('RE-1')
        ->issueDate('2026-10-14')
        ->deliveryDate('2026-10-14')
        ->buyer(fn ($b) => $b->name('Kunde AG')->vatId('DE987654321')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->line(Line::make('Consulting')->netPrice('80.00'));
}

it('gives lines without VAT the standard rate of the seller country', function () {
    expect(invoiceWithoutVat()->build()->lines[0]->vat->presetKey)->toBe('de.standard');
});

it('uses quittio.defaults.vat', function () {
    config()->set('quittio.defaults.vat', 'de.reduced_7');

    expect(invoiceWithoutVat()->build()->lines[0]->vat->presetKey)->toBe('de.reduced');
});

it('uses the small business preset for small business sellers', function () {
    config()->set('quittio.seller.small_business', true);

    expect(invoiceWithoutVat()->build()->lines[0]->vat->presetKey)->toBe('de.small_business');
});

it('rejects an unknown preset in quittio.defaults.vat', function () {
    config()->set('quittio.defaults.vat', 'de.standard_16');

    expect(fn () => invoiceWithoutVat()->build())->toThrow(ConfigurationException::class, 'quittio.defaults.vat: unknown tax preset "de.standard_16"');
});

it('cancels a rendered invoice in its formats', function () {
    $invoice = Quittio::make(Scenarios::make('simple'))->formats(['zugferd', 'xrechnung-cii'])->render();

    $cancellation = $invoice->cancel('ST-2026-0007', reason: 'Ordered twice');
    $partial = $invoice->cancel('ST-2026-0008', lines: [['line' => '1', 'quantity' => '1']], issueDate: '2026-10-20');

    expect($cancellation->data()->number)->toBe('ST-2026-0007')
        ->and($cancellation->data()->precedingInvoices[0]->number)->toBe($invoice->data()->number)
        ->and($cancellation->data()->totals?->grossTotal->isEqualTo($invoice->data()->totals?->grossTotal))->toBeTrue()
        ->and($cancellation->formats())->toBe(['zugferd', 'xrechnung-cii'])
        ->and($cancellation->filename())->toStartWith('Stornorechnung_ST-2026-0007')
        ->and($partial->data()->issueDate->toIsoString())->toBe('2026-10-20')
        ->and($partial->data()->lines[0]->quantity->toString())->toBe('1');
});

it('cancels with the fake as well', function () {
    $fake = Quittio::fake();

    Quittio::make(Scenarios::make('simple'))->render()->cancel('ST-2026-0009');

    expect($fake->rendered())->toHaveCount(2)
        ->and($fake->rendered()[1]->data()->number)->toBe('ST-2026-0009');
});

it('defaults the country to the only installed country pack', function () {
    config()->set('quittio.defaults.country', null);
    config()->set('quittio.seller.address.country', null);

    expect(Quittio::core()->config()->country)->toBe('DE')
        ->and(Seller::fromConfig()->address->countryCode)->toBe('DE');
});

it('has no default country with several country packs', function () {
    config()->set('quittio.defaults.country', null);
    Quittio::registerCountry(new CountryGeneric('AT'));

    expect(Quittio::core()->config()->country)->toBeNull();

    config()->set('quittio.defaults.country', 'AT');
    expect(Quittio::core()->config()->country)->toBe('AT');
});
