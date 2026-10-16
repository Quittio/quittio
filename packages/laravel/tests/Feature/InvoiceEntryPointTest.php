<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Line;
use Quittio\Core\Builder\Party;
use Quittio\Core\Data\Meta;
use Quittio\Laravel\Builder\RenderableInvoiceBuilder;
use Quittio\Laravel\Contracts\InvoiceDefaults;
use Quittio\Laravel\Facades\Quittio;

function entryPointInvoice(): RenderableInvoiceBuilder
{
    return Quittio::invoice()
        ->number('RE-2026-0042')
        ->issueDate('2026-10-14')
        ->deliveryDate('2026-10-14')
        ->buyerReference('04011000-12345-03')
        ->buyer(Party::make('Stadt Beispielstadt')->address('Rathausplatz 1', '54321', 'Beispielstadt', 'DE')->electronicAddress('04011000-12345-03', '0204'))
        ->line(Line::make('Consulting')->quantity(4)->netPrice('120.00'));
}

it('starts an invoice with the seller, the bank account and the defaults of the configuration', function () {
    config()->set('quittio.seller.small_business', true);

    $invoice = Quittio::invoice()
        ->buyer(Party::make('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->line(Line::make('Consulting')->netPrice('80.00'))
        ->build();

    expect($invoice->seller->name)->toBe('Muster GmbH')
        ->and($invoice->paymentInstructions?->primaryCreditTransfer()?->accountId)->toBe('DE02120300000000202051')
        ->and($invoice->meta->hasFlag(Meta::FLAG_SMALL_BUSINESS))->toBeTrue()
        ->and($invoice->lines[0]->vat->presetKey)->toBe('de.small_business');
});

it('renders at the end of the chain, in one or several formats', function () {
    config()->set('quittio.seller.contact', ['name' => 'Max Muster', 'phone' => '+49 30 1234567', 'email' => 'rechnung@muster.de']);
    $automatic = entryPointInvoice()->render();
    $one = entryPointInvoice()->render('xrechnung-ubl');
    $several = entryPointInvoice()->render(['zugferd', 'xrechnung-cii']);

    expect($automatic->format())->toBe('xrechnung-cii')
        ->and($one->format())->toBe('xrechnung-ubl')
        ->and($several->formats())->toBe(['zugferd', 'xrechnung-cii'])
        ->and($several->data()->totals?->amountDue->isEqualTo('571.20'))->toBeTrue();
});

it('starts documents with the seller of the configuration', function () {
    $quote = Quittio::quote()
        ->number('AN-2026-0031')
        ->buyer(Party::make('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->line(Line::make('Workshop')->netPrice('1200.00'));
    $reminder = Quittio::paymentReminder(1)
        ->number('M-2026-0005')
        ->buyer(Party::make('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->openItem('RE-2026-0042', '2026-08-03', '1190.00', dueDate: '2026-08-17');

    $rendered = $quote->render();

    expect($rendered->data()->seller->name)->toBe('Muster GmbH')
        ->and($rendered->filename())->toBe('Angebot_AN-2026-0031.pdf')
        ->and($rendered->data()->lines[0]->vat->presetKey)->toBe('de.standard')
        ->and($reminder->build()->paymentInstructions?->primaryCreditTransfer()?->accountId)->toBe('DE02120300000000202051')
        ->and(Quittio::deliveryNote()->buyer(Party::make('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
            ->line(['name' => 'Router'])->build()->seller->name)->toBe('Muster GmbH');
});

it('takes the seller from the bound InvoiceDefaults, also with the fake', function () {
    app()->bind(InvoiceDefaults::class, fn () => new class implements InvoiceDefaults
    {
        public function apply(InvoiceBuilder $builder): void
        {
            $builder->seller(Party::make('Issuer GmbH')->vatId('DE123456789')->address('Ring 1', '10115', 'Berlin', 'DE'));
        }
    });
    $fake = Quittio::fake();

    entryPointInvoice()->render();

    expect($fake->rendered()[0]->data()->seller->name)->toBe('Issuer GmbH');
});

it('gives another seller nothing of the seller of the configuration', function () {
    config()->set('quittio.seller.small_business', true);

    $invoice = Quittio::invoice()
        ->seller(Party::make('Lisa Klein')->taxNumber('21/815/08150')->identifier('21/815/08150')->address('Gartenstraße 7', '12345', 'Musterstadt', 'DE'))
        ->buyer(Party::make('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->line('Consulting', netPrice: '80.00')
        ->build();
    $quote = Quittio::quote()
        ->seller(Party::make('Lisa Klein')->address('Gartenstraße 7', '12345', 'Musterstadt', 'DE'))
        ->buyer(Party::make('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->line('Consulting', netPrice: '80.00')
        ->build();

    expect($invoice->seller->name)->toBe('Lisa Klein')
        ->and($invoice->paymentInstructions)->toBeNull()
        ->and($invoice->meta->hasFlag(Meta::FLAG_SMALL_BUSINESS))->toBeFalse()
        ->and($invoice->lines[0]->vat->presetKey)->toBe('de.standard')
        ->and($quote->paymentInstructions)->toBeNull();
});
