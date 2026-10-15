<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Party\PayeeParty;
use Quittio\Core\Data\Payment\PaymentTerms;
use Quittio\Core\Enums\IdentifierScheme;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Xml\Cii\CiiWriter;

it('builds invoice data from the fluent API', function () {
    $invoice = Invoices::simple()->buyerReference('04011000-12345-03')->note('Thank you')->build();

    expect($invoice)->toBeInstanceOf(InvoiceData::class)
        ->and($invoice->number)->toBe('INV-2026-0001')
        ->and($invoice->issueDate->toIsoString())->toBe('2026-10-03')
        ->and($invoice->typeCode)->toBe(InvoiceTypeCode::CommercialInvoice)
        ->and($invoice->seller->vatId)->toBe('DE123456789')
        ->and($invoice->buyer->address->city)->toBe('Example Town')
        ->and($invoice->delivery?->actualDeliveryDate?->toIsoString())->toBe('2026-09-30')
        ->and($invoice->buyerReference)->toBe('04011000-12345-03')
        ->and($invoice->notes[0]->content)->toBe('Thank you')
        ->and($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines[0]->id)->toBe('1')
        ->and($invoice->lines[0]->unit)->toBe(UnitCode::Hour)
        ->and($invoice->lines[0]->price->netPrice?->toString())->toBe('100.00')
        ->and($invoice->paymentInstructions?->creditTransfers[0]->accountId)->toBe('DE02120300000000202051')
        ->and($invoice->isCalculated())->toBeFalse();
});

it('numbers lines automatically and keeps explicit line ids', function () {
    $invoice = Invoices::base()
        ->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('1')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->id('X-7')->name('B')->quantity(1)->netPrice('1')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('C')->quantity(1)->netPrice('1')->vat(VatCategory::StandardRate, '19'))
        ->build();

    expect(array_map(fn ($l) => $l->id, $invoice->lines))->toBe(['1', 'X-7', '3']);
});

it('requires lines, seller and buyer', function () {
    InvoiceBuilder::make()->build();
})->throws(InvalidInvoiceData::class);

it('derives the net price from list price and price discount', function () {
    $invoice = Invoices::base()
        ->line(fn ($l) => $l->name('A')->quantity(2)->listPrice('120.00', '20.00')->vat(VatCategory::StandardRate, '19'))
        ->build();

    expect($invoice->lines[0]->price->netPrice?->toString())->toBe('100.00')
        ->and($invoice->lines[0]->price->grossPrice?->toString())->toBe('120.00');
});

it('starts a builder from existing data', function () {
    $original = Invoices::simple()->build();
    $changed = InvoiceBuilder::from($original)->number('INV-2')->build();

    expect($changed->number)->toBe('INV-2')
        ->and($changed->lines)->toEqual($original->lines)
        ->and($changed->seller)->toEqual($original->seller);
});

it('supports macros', function () {
    InvoiceBuilder::macro('internalNote', fn (string $text) => $this->note('[internal] '.$text));

    $invoice = Invoices::simple()->internalNote('check')->build();

    // Not flushed: that would remove the macros of country packs for later tests of the process.
    expect($invoice->notes[0]->content)->toBe('[internal] check');
});

it('changes and removes existing lines', function () {
    $original = Invoices::simple()->line(fn ($l) => $l->name('Extra')->quantity(1, UnitCode::Piece)->netPrice('10.00')->vat(VatCategory::StandardRate, '19'))->build();

    $changed = InvoiceBuilder::from($original)
        ->updateLine('1', fn (InvoiceLine $l): InvoiceLine => $l->withNetPrice('80.00'))
        ->removeLine('2')
        ->build();

    expect($changed->lines)->toHaveCount(1)
        ->and((string) $changed->lines[0]->price->netPrice)->toBe('80.00')
        ->and($changed->lines[0]->netAmount)->toBeNull()
        ->and(fn () => InvoiceBuilder::from($original)->updateLine('9', fn (InvoiceLine $l): InvoiceLine => $l))->toThrow(InvalidArgumentException::class, 'no line "9"')
        ->and(fn () => InvoiceBuilder::from($original)->removeLine('9'))->toThrow(InvalidArgumentException::class);
});

it('takes over the exchange rate implied by a declared VAT total in accounting currency', function () {
    $calculator = new En16931Calculator;
    $original = $calculator->calculate(Invoices::simple()->currency('USD')->taxCurrency('EUR', '0.9')->build());
    $read = (new InvoiceReader)->read((new CiiWriter)->serialize($original, ProfileRegistry::withDefaults()->get('en16931')))->data;

    $rebuilt = $calculator->calculate(InvoiceBuilder::from($read)->number('INV-2')->build());

    expect($read->meta->exchangeRate)->toBeNull()
        ->and($rebuilt->meta->exchangeRate?->toString())->toBe('0.9')
        ->and($rebuilt->totals?->vatTotalInTaxCurrency?->isEqualTo($original->totals?->vatTotalInTaxCurrency))->toBeTrue();
});

it('recalculates percentage allowances and charges from the changed lines', function () {
    $calculator = new En16931Calculator;
    $original = $calculator->calculate(Invoices::base()
        ->line(fn ($l) => $l->name('A')->quantity(10)->netPrice('100.00')->vat(VatCategory::StandardRate, '19')
            ->allowance(percent: '10', reason: 'Discount')
            ->charge(percent: '2', reason: 'Packaging', baseAmount: '500.00'))
        ->line(fn ($l) => $l->name('B')->quantity(1)->netPrice('500.00')->vat(VatCategory::StandardRate, '19'))
        ->allowance(VatCategory::StandardRate, '19', percent: '5', reason: 'Loyalty')
        ->charge(VatCategory::StandardRate, '19', percent: '1', reason: 'Insurance', baseAmount: '1000.00')
        ->build());

    $changed = $calculator->calculate(InvoiceBuilder::from($original)->number('INV-2')
        ->updateLine('1', fn (InvoiceLine $l): InvoiceLine => $l->withNetPrice('80.00'))
        ->build());
    $unchanged = $calculator->calculate(InvoiceBuilder::from($original)->number('INV-3')->build());
    $line = $changed->lines[0];

    expect((string) $original->lines[0]->allowances[0]->amount?->getAmount())->toBe('100.00')
        ->and((string) $line->allowances[0]->amount?->getAmount())->toBe('80.00')
        ->and((string) $line->allowances[0]->baseAmount?->getAmount())->toBe('800.00')
        // An explicit base amount stays.
        ->and((string) $line->charges[0]->amount?->getAmount())->toBe('10.00')
        ->and((string) $line->charges[0]->baseAmount?->getAmount())->toBe('500.00')
        ->and((string) $line->netAmount?->getAmount())->toBe('730.00')
        ->and((string) $changed->allowances[0]->amount?->getAmount())->toBe('61.50')
        ->and((string) $changed->allowances[0]->baseAmount?->getAmount())->toBe('1230.00')
        ->and((string) $changed->charges[0]->amount?->getAmount())->toBe('10.00')
        ->and($unchanged->allowances)->toEqual($original->allowances)
        ->and($unchanged->totals)->toEqual($original->totals);
});

it('keeps the price mode of a changed line', function () {
    $calculator = new En16931Calculator;
    $gross = $calculator->calculate(Invoices::base()
        ->line(fn ($l) => $l->name('Shirt')->quantity(2)->priceInclVat('19.99')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('Shipping')->quantity(1)->priceInclVat('4.95')->vat(VatCategory::StandardRate, '19'))
        ->build());

    $changed = $calculator->calculate(InvoiceBuilder::from($gross)->number('INV-2')
        ->updateLine('1', fn (InvoiceLine $l): InvoiceLine => $l->withPriceInclVat('17.99'))
        ->build());

    expect((string) $changed->totals?->amountDue->getAmount())->toBe('40.93')
        ->and(fn () => $gross->lines[0]->withNetPrice('15.00'))->toThrow(InvalidArgumentException::class, 'withPriceInclVat()')
        ->and(fn () => Invoices::simple()->build()->lines[0]->withPriceInclVat('119.00'))->toThrow(InvalidArgumentException::class, 'withNetPrice()');
});

it('replaces the payment instructions on a second call to payment()', function () {
    $twice = Scenarios::foreignCurrency()->build()->paymentInstructions;
    $debit = Scenarios::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10.00')->vat(VatCategory::StandardRate, '19'))
        ->payment(PaymentMeansCode::SepaDirectDebit)
        ->directDebit('MANDATE-1', 'DE98ZZZ09999999999', 'DE02120300000000202051')
        ->build()->paymentInstructions;
    $defaultAccount = Invoices::base()->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10.00')->vat(VatCategory::StandardRate, '19'))
        ->payment(PaymentMeansCode::CreditTransfer, remittanceInformation: 'INV-1')
        ->build()->paymentInstructions;

    // Scenarios::foreignCurrency() calls payment() again with the same account (code 30).
    expect($twice?->meansCode)->toBe(PaymentMeansCode::CreditTransfer)
        ->and($twice?->creditTransfers)->toHaveCount(1)
        ->and($twice?->remittanceInformation)->toBe('RE-2026-0042')
        // Switching to direct debit drops the bank account of the credit transfer (BR-DE-25-b).
        ->and($debit?->creditTransfers)->toBe([])
        ->and($debit?->directDebit?->mandateReference)->toBe('MANDATE-1')
        ->and($debit?->remittanceInformation)->toBe('RE-2026-0042')
        // Without an account, the existing account is kept for credit transfers.
        ->and($defaultAccount?->creditTransfers[0]->accountId ?? null)->toBe('DE02120300000000202051')
        ->and($defaultAccount?->remittanceInformation)->toBe('INV-1');
});

it('sets the payee with identifier schemes, as PayeeParty or with a PartyBuilder', function () {
    $named = Invoices::simple()->payee('Factor Bank AG', '4000001000005', 'HRB 1234', '0088', '0002')->build()->payee;
    $party = new PayeeParty('Collector GmbH', Identifier::of('C-1'));
    $continued = Invoices::simple()
        ->payee($party)
        ->payee(fn (PartyBuilder $p) => $p->legalRegistration('HRB 99', '0002'))
        ->build()->payee;
    $built = Invoices::simple()->payee(fn (PartyBuilder $p) => $p->name('Factor')->identifier('4000001000005', IdentifierScheme::Gln))->build()->payee;

    expect($named?->identifier?->value)->toBe('4000001000005')
        ->and($named?->identifier?->schemeValue())->toBe('0088')
        ->and($named?->legalRegistration?->schemeValue())->toBe('0002')
        ->and(Invoices::simple()->payee($party)->build()->payee)->toBe($party)
        ->and([$continued?->name, $continued?->identifier?->value, $continued?->legalRegistration?->value])->toBe(['Collector GmbH', 'C-1', 'HRB 99'])
        ->and([$built?->name, $built?->identifier?->scheme])->toBe(['Factor', IdentifierScheme::Gln])
        ->and(fn () => Invoices::simple()->payee($party, 'X-1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Invoices::simple()->payee(fn (PartyBuilder $p) => $p->identifier('X'))->build())->toThrow(InvalidInvoiceData::class, 'BT-59');
});

it('continues existing payment terms with a callable, also after from()', function () {
    $original = Invoices::simple()->paymentTerms(fn ($t) => $t->text('Net 30')->dueInDays(30)->cashDiscount(10, '2', '500.00')->lateFee(text: 'Interest'))->build();

    $terms = InvoiceBuilder::from($original)->paymentTerms(fn ($t) => $t->cashDiscount(20, '1'))->build()->paymentTerms;
    $replaced = InvoiceBuilder::from($original)->paymentTerms('Due on receipt')->build()->paymentTerms;
    $object = Invoices::simple()->paymentTerms(new PaymentTerms(text: 'Pay soon', dueDays: 5))->paymentTerms(fn ($t) => $t->cashDiscount(3, '1'))->build()->paymentTerms;

    expect([$terms?->text, $terms?->dueDays, $terms?->lateFeeTerms?->text])->toBe(['Net 30', 30, 'Interest'])
        ->and(array_map(static fn ($d): array => [$d->days, (string) $d->percent, $d->baseAmount === null ? null : (string) $d->baseAmount->getAmount()], $terms->discounts ?? []))
        ->toBe([[10, '2.00', '500.00'], [20, '1.00', null]])
        ->and([$replaced?->text, $replaced?->dueDays, $replaced?->discounts])->toBe(['Due on receipt', null, []])
        ->and([$object?->text, $object?->dueDays, count($object->discounts ?? [])])->toBe(['Pay soon', 5, 1]);
});

it('builds drafts without lines that can be calculated', function () {
    $draft = (new En16931Calculator)->calculate(Invoices::base()->buildDraft());

    expect($draft->lines)->toBe([])
        ->and($draft->isCalculated())->toBeTrue()
        ->and((string) $draft->totals?->amountDue->getAmount())->toBe('0.00');
});

it('gives macros access to the builder state on subclasses', function () {
    $subclass = new class extends InvoiceBuilder {};
    InvoiceBuilder::macro('currencyForTest', function (): string {
        return $this->currency;
    });

    expect($subclass::make()->currency('USD')->currencyForTest())->toBe('USD');
});

it('accepts amounts as BigDecimal, int or string and rejects floats', function () {
    $data = (new En16931Calculator)->calculate(Invoices::simple()->prepaid(Brick\Math\BigDecimal::of('10.00'))->addPrepaid(5)->rounding('0.01')->discountAmount(1)->build());

    expect($data->totals?->prepaidAmount?->getAmount()->toString())->toBe('15.00')
        ->and($data->totals?->roundingAmount?->getAmount()->toString())->toBe('0.01')
        ->and(fn () => Invoices::simple()->prepaid(1.5))->toThrow(InvalidArgumentException::class);
});
