<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Line;
use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Contracts\ProvidesInvoiceLine;
use Quittio\Core\Contracts\ProvidesParty;
use Quittio\Core\Data\Meta;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Tax\CustomTaxPreset;
use Quittio\Core\Tests\Support\Invoices;

afterEach(fn () => InvoiceBuilder::useDefaultVat(null));

function reducedPreset(): CustomTaxPreset
{
    return CustomTaxPreset::make('test.reduced_7', VatCategory::StandardRate, '7');
}

it('adds lines from Line::make() without a callback, with a quantity of 1 by default', function () {
    $invoice = Invoices::base()
        ->line(Line::make('Logo design')->netPrice('650.00')->vat(VatCategory::StandardRate, '19'))
        ->build();

    expect($invoice->lines[0]->item->name)->toBe('Logo design')
        ->and($invoice->lines[0]->quantity->toString())->toBe('1')
        ->and($invoice->lines[0]->unit)->toBe(UnitCode::Unit);
});

it('adds lines from arrays whose keys are the methods of the line builder', function () {
    $invoice = Invoices::base()
        ->lines([
            ['name' => 'Consulting', 'quantity' => [4, UnitCode::Hour], 'netPrice' => '120.00', 'vat' => VatCategory::StandardRate],
            ['name' => 'Seminar', 'quantity' => 3, 'netPrice' => '400.00', 'vat' => [VatCategory::StandardRate, '19'],
                'allowance' => ['percent' => '10', 'reason' => 'Early bird'], 'description' => null, 'sellerId' => 'S-1'],
        ])
        ->build();

    expect($invoice->lines[0]->quantity->toString())->toBe('4')
        ->and($invoice->lines[0]->unit)->toBe(UnitCode::Hour)
        ->and($invoice->lines[1]->allowances[0]->percent?->toString())->toBe('10')
        ->and($invoice->lines[1]->item->sellerId)->toBe('S-1')
        ->and($invoice->lines[1]->item->description)->toBeNull();
});

it('rejects unknown keys and lists of values as line', function () {
    expect(fn () => Invoices::base()->line(['name' => 'Consulting', 'price' => '120.00']))
        ->toThrow(InvalidArgumentException::class, 'Unknown key "price" of an invoice line')
        ->and(fn () => Invoices::base()->line(['Consulting', '120.00']))
        ->toThrow(InvalidArgumentException::class, 'needs keys');
});

it('copies a line builder, so a line can be used as template', function () {
    $template = Line::make('Hosting')->quantity(1, UnitCode::Month)->netPrice('49.00')->vat(VatCategory::StandardRate, '19');
    $builder = Invoices::base()->line($template);
    $template->netPrice('59.00');

    expect($builder->line($template)->build()->lines)
        ->sequence(
            fn ($line) => $line->price->netPrice->toString()->toBe('49.00'),
            fn ($line) => $line->price->netPrice->toString()->toBe('59.00'),
        );
});

it('takes parties from Party::make() and from application models', function () {
    $customer = new class implements ProvidesParty
    {
        public function fillParty(PartyBuilder $party): void
        {
            $party->name('Kunde AG')->vatId('DE987654321')->address('Weg 1', '54321', 'Beispielstadt', 'DE');
        }
    };
    $seller = Party::make('Muster GmbH')->vatId('DE123456789')->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE');

    $invoice = Invoices::simple()
        ->seller($seller)
        ->seller(fn (PartyBuilder $s) => $s->contact('Max Muster'))   // a callable continues the party
        ->buyer($customer)
        ->build();

    expect($invoice->seller->name)->toBe('Muster GmbH')
        ->and($invoice->seller->contact?->name)->toBe('Max Muster')
        ->and($invoice->buyer->name)->toBe('Kunde AG')
        ->and($seller->buildSeller()->contact)->toBeNull();
});

it('applies the default VAT of the invoice to lines and sub lines without own VAT', function () {
    $invoice = Invoices::base()
        ->defaultVat(VatCategory::StandardRate, '19')
        ->line(Line::make('Development')->quantity(10, UnitCode::Hour)->netPrice('95.00'))
        ->line(Line::make('Book')->netPrice('39.00')->vat(reducedPreset()))
        ->line(Line::make('Workstation')->subLine(fn (LineBuilder $s) => $s->name('Monitor')->netPrice('250.00')))
        ->build();

    expect($invoice->lines[0]->vat->rate?->toString())->toBe('19')
        ->and($invoice->lines[1]->vat->presetKey)->toBe('test.reduced_7')
        ->and($invoice->lines[2]->vat->rate?->toString())->toBe('19')
        ->and($invoice->lines[2]->subLines[0]->vat->rate?->toString())->toBe('19');
});

it('uses the application default VAT with the seller country and the small business flag', function () {
    $calls = [];
    InvoiceBuilder::useDefaultVat(function (?string $country, bool $smallBusiness) use (&$calls) {
        $calls[] = [$country, $smallBusiness];

        return reducedPreset();
    });

    $invoice = Invoices::base()->flag(Meta::FLAG_SMALL_BUSINESS)->line(Line::make('Consulting')->netPrice('80.00'))->build();
    Invoices::simple()->build();                                       // every line has VAT: not asked

    expect($invoice->lines[0]->vat->presetKey)->toBe('test.reduced_7')
        ->and($calls)->toBe([['DE', true]]);

    InvoiceBuilder::useDefaultVat(CustomTaxPreset::make('test.standard', VatCategory::StandardRate, '19'));
    expect(Invoices::base()->line(Line::make('Consulting')->netPrice('80.00'))->build()->lines[0]->vat->presetKey)->toBe('test.standard');
});

it('still requires VAT when there is no default', function () {
    expect(fn () => Invoices::base()->line(Line::make('Consulting')->netPrice('80.00'))->build())
        ->toThrow(InvalidInvoiceData::class, 'BG-30');
});

it('accepts arrays, builders and models in documents, with the delivery note defaults', function () {
    $item = new class implements ProvidesInvoiceLine
    {
        public function fillLine(LineBuilder $line): void
        {
            $line->name('Cable')->quantity(5, UnitCode::Metre)->netPrice('2.00');
        }
    };

    $note = DocumentBuilder::deliveryNote()
        ->number('LS-1')
        ->seller(Party::make('Muster GmbH')->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE'))
        ->buyer(Party::make('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->lines([['name' => 'Router', 'quantity' => 2], Line::make('Switch')])
        ->build();
    $quote = DocumentBuilder::quote()
        ->number('AN-1')
        ->seller(Party::make('Muster GmbH')->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE'))
        ->buyer(Party::make('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->defaultVat(VatCategory::StandardRate, '19')
        ->line(['name' => 'Workshop', 'netPrice' => '1200.00'])
        ->optionalLine(Line::make('Follow-up')->netPrice('480.00'))
        ->line($item)
        ->build();

    expect($note->lines[0]->vat->category)->toBe(VatCategory::NotSubjectToVat)
        ->and($note->lines[1]->price->netPrice?->toString())->toBe('0')
        ->and($note->lines[1]->quantity->toString())->toBe('1')
        ->and($quote->lines[0]->vat->rate?->toString())->toBe('19')
        ->and($quote->lines[1]->vat->rate?->toString())->toBe('19')
        ->and($quote->lines[2]->item->name)->toBe('Cable');
});
