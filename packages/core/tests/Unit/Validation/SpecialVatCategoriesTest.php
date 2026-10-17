<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Support\Amount;
use Quittio\Core\Tests\Support\Official;
use Quittio\Core\Tests\Support\Validate;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;

/**
 * Invoice of a seller on the Canary Islands (IGIC, "L") or in Ceuta (IPSI, "M") to a buyer on the
 * Spanish mainland.
 */
function specialVatSpanish(VatCategory $category): InvoiceBuilder
{
    [$postcode, $city] = $category === VatCategory::CanaryIslands ? ['35001', 'Las Palmas de Gran Canaria'] : ['51001', 'Ceuta'];

    return InvoiceBuilder::make()
        ->number('FAC-2026-0007')
        ->issueDate('2026-10-03')
        ->currency('EUR')
        ->seller(fn ($s) => $s
            ->name('Comercial Atlántica S.L.')
            ->vatId('ESB35123456')
            ->address('Calle Mayor 1', $postcode, $city, 'ES')
            ->contact('Ana García', '+34 928 123456', 'facturas@atlantica.example')
            ->electronicAddress('facturas@atlantica.example', 'EM'))
        ->buyer(fn ($b) => $b
            ->name('Distribuciones Peninsulares S.A.')
            ->vatId('ESA28123456')
            ->address('Gran Vía 10', '28013', 'Madrid', 'ES')
            ->electronicAddress('compras@peninsulares.example', 'EM'))
        ->deliveryDate('2026-09-30')
        ->paymentTerms(fn ($t) => $t->dueInDays(30))
        ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'ES9121000418450200051332', bic: 'CAIXESBBXXX');
}

function specialVatIgic(): InvoiceBuilder
{
    return specialVatSpanish(VatCategory::CanaryIslands)
        ->line(fn ($l) => $l->name('Consultoría')->quantity(10, UnitCode::Hour)->netPrice('80.00')->vat(VatCategory::CanaryIslands, '7'))
        ->line(fn ($l) => $l->name('Material de oficina')->quantity(4, UnitCode::Piece)->netPrice('12.50')->vat(VatCategory::CanaryIslands, '3'))
        ->charge(VatCategory::CanaryIslands, '7', amount: '15.00', reason: 'Transporte');
}

function specialVatIpsi(): InvoiceBuilder
{
    return specialVatSpanish(VatCategory::CeutaMelilla)
        ->line(fn ($l) => $l->name('Mantenimiento')->quantity(1, UnitCode::LumpSum)->netPrice('450.00')->vat(VatCategory::CeutaMelilla, '4'))
        ->line(fn ($l) => $l->name('Repuestos')->quantity(3, UnitCode::Piece)->netPrice('19.90')->vat(VatCategory::CeutaMelilla, '10'))
        ->allowance(VatCategory::CeutaMelilla, '4', amount: '20.00', reason: 'Descuento');
}

function specialVatInvoice(VatCategory $category): InvoiceBuilder
{
    return $category === VatCategory::CanaryIslands ? specialVatIgic() : specialVatIpsi();
}

/**
 * Domestic Italian invoice with split payment ("B").
 */
function specialVatSplitPayment(): InvoiceBuilder
{
    return InvoiceBuilder::make()
        ->number('FT-2026-0011')
        ->issueDate('2026-10-03')
        ->currency('EUR')
        ->seller(fn ($s) => $s
            ->name('Servizi Romani S.r.l.')
            ->vatId('IT01234567890')
            ->address('Via del Corso 12', '00186', 'Roma', 'IT')
            ->contact('Marco Rossi', '+39 06 1234567', 'fatture@servizi.example')
            ->electronicAddress('fatture@servizi.example', 'EM'))
        ->buyer(fn ($b) => $b
            ->name('Comune di Esempio')
            ->vatId('IT09876543210')
            ->address('Piazza del Municipio 1', '20121', 'Milano', 'IT')
            ->electronicAddress('protocollo@comune.example', 'EM'))
        ->deliveryDate('2026-09-30')
        ->paymentTerms(fn ($t) => $t->dueInDays(30))
        ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'IT60X0542811101000000123456', bic: 'BPMOIT22XXX')
        ->line(fn ($l) => $l->name('Servizio di pulizia')->quantity(1, UnitCode::LumpSum)->netPrice('1000.00')->vat(VatCategory::TransferredVatItaly, '22'));
}

function specialVatCalculated(InvoiceBuilder $builder): InvoiceData
{
    return (new En16931Calculator)->calculate($builder->build());
}

it('accepts invoices with IGIC (L), IPSI (M) and split payment (B)', function (Closure $build) {
    expect(Validate::data($build())->errors())->toBe([]);
})->with([
    'IGIC 7 % and 3 %' => [fn () => specialVatIgic()->build()],
    'IGIC 0 %' => [fn () => specialVatSpanish(VatCategory::CanaryIslands)->line(fn ($l) => $l->name('Libro')->quantity(1)->netPrice('20.00')->vat(VatCategory::CanaryIslands, '0'))->build()],
    'IGIC with the seller tax number only' => [fn () => specialVatIgic()->seller(fn ($s) => $s->vatId(null)->taxNumber('B35123456')->legalRegistration('B35123456'))->build()],
    'IPSI 4 % and 10 %' => [fn () => specialVatIpsi()->build()],
    'IPSI 0 %' => [fn () => specialVatSpanish(VatCategory::CeutaMelilla)->line(fn ($l) => $l->name('Pan')->quantity(1)->netPrice('3.00')->vat(VatCategory::CeutaMelilla, '0'))->build()],
    'split payment' => [fn () => specialVatSplitPayment()->build()],
    'split payment with Italian deliver to address and origin' => [fn () => specialVatSplitPayment()
        ->deliverTo('Comune di Esempio', Address::make('Via Roma 3', '20122', 'Milano', 'IT'))
        ->line(fn ($l) => $l->name('Arredo')->quantity(1)->netPrice('200.00')->originCountry('IT')->vat(VatCategory::TransferredVatItaly, '22'))
        ->build()],
]);

it('calculates one VAT breakdown per rate with VAT for IGIC and IPSI', function () {
    $igic = specialVatCalculated(specialVatIgic());
    $ipsi = specialVatCalculated(specialVatIpsi());

    expect(array_map(fn ($b) => [$b->category, $b->rate?->toString(), $b->taxableAmount->getAmount()->toString(), $b->taxAmount->getAmount()->toString()], $igic->vatBreakdown))->toBe([
        [VatCategory::CanaryIslands, '7', '815.00', '57.05'],
        [VatCategory::CanaryIslands, '3', '50.00', '1.50'],
    ])->and(array_map(fn ($b) => [$b->category, $b->rate?->toString(), $b->taxableAmount->getAmount()->toString(), $b->taxAmount->getAmount()->toString()], $ipsi->vatBreakdown))->toBe([
        [VatCategory::CeutaMelilla, '4', '430.00', '17.20'],
        [VatCategory::CeutaMelilla, '10', '59.70', '5.97'],
    ])->and($igic->totals?->vatTotal->getAmount()->toString())->toBe('58.55');
});

it('reports the IGIC and IPSI rules', function (VatCategory $category, string $prefix) {
    $other = $category === VatCategory::CanaryIslands ? 'BR-AG' : 'BR-AF';
    $valid = Validate::codes(Validate::data(specialVatInvoice($category)->build()));
    $invoice = specialVatCalculated(specialVatInvoice($category));
    $declared = fn (array $changes) => Validate::codes(Validate::data($invoice->with($changes), calculate: false));
    $first = $invoice->vatBreakdown[0];

    expect(array_filter($valid, fn ($c) => str_starts_with($c, $prefix) || str_starts_with($c, $other)))->toBe([])
        // -01: a breakdown with the category is missing
        ->and($declared(['vatBreakdown' => [$invoice->vatBreakdown[1]->with(['category' => VatCategory::StandardRate])]]))->toContain("{$prefix}-01")
        // -02: neither seller VAT identifier, tax number nor tax representative
        ->and(Validate::codes(Validate::data(specialVatInvoice($category)->seller(fn ($s) => $s->vatId(null))->build())))->toContain("{$prefix}-02")
        // -05: negative rate on a line, an allowance or a charge (BR-AF-05 … -07)
        ->and(Validate::codes(Validate::data(specialVatSpanish($category)->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10')->vat($category, '-1'))->build())))->toContain("{$prefix}-05")
        ->and(Validate::codes(Validate::data(specialVatInvoice($category)->allowance($category, '-1', amount: '1.00', reason: 'X')->build())))->toContain("{$prefix}-05")
        // -08: taxable amount of a rate
        ->and($declared(['vatBreakdown' => [$first->with(['taxableAmount' => Amount::of('1.00', 'EUR')]), $invoice->vatBreakdown[1]]]))->toContain("{$prefix}-08")
        // -09: tax amount of a rate
        ->and($declared(['vatBreakdown' => [$first->with(['taxAmount' => Amount::of('99.00', 'EUR')]), $invoice->vatBreakdown[1]]]))->toContain("{$prefix}-09")
        ->and($declared(['vatBreakdown' => [$first->with(['taxAmount' => Amount::of('0.00', 'EUR')]), $invoice->vatBreakdown[1]]]))->toContain("{$prefix}-09")
        // -10: no exemption reason
        ->and($declared(['vatBreakdown' => [$first->with(['exemptionReason' => 'Exento']), $invoice->vatBreakdown[1]]]))->toContain("{$prefix}-10")
        ->and($declared(['vatBreakdown' => [$first->with(['exemptionReasonCode' => Quittio\Core\Enums\VatExemptionReasonCode::NotSubjectToVat]), $invoice->vatBreakdown[1]]]))->toContain("{$prefix}-10");
})->with([
    'IGIC' => [VatCategory::CanaryIslands, 'BR-AF'],
    'IPSI' => [VatCategory::CeutaMelilla, 'BR-AG'],
]);

it('allows several IGIC and IPSI breakdowns but requires at least one', function (VatCategory $category, string $prefix) {
    $invoice = specialVatCalculated(specialVatInvoice($category));

    expect($invoice->vatBreakdown)->toHaveCount(2)
        ->and(Validate::codes(Validate::data($invoice, calculate: false)))->not->toContain("{$prefix}-01")
        ->and(Validate::codes(Validate::data($invoice->with(['vatBreakdown' => []]), calculate: false)))->toContain("{$prefix}-01");
})->with([
    'IGIC' => [VatCategory::CanaryIslands, 'BR-AF'],
    'IPSI' => [VatCategory::CeutaMelilla, 'BR-AG'],
]);

it('reports split payment outside Italy (BR-B-01)', function (Closure $build, string $path) {
    $messages = array_values(array_filter(Validate::data($build())->messages, fn ($m) => $m->code === 'BR-B-01'));

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->path)->toBe($path);
})->with([
    'German buyer' => [fn () => specialVatSplitPayment()->buyer(fn ($b) => $b->vatId('DE987654321')->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))->build(), 'buyer.address.countryCode'],
    'Austrian seller' => [fn () => specialVatSplitPayment()->seller(fn ($s) => $s->vatId('ATU12345678')->address('Ring 1', '1010', 'Wien', 'AT'))->build(), 'seller.address.countryCode'],
    'delivery to France' => [fn () => specialVatSplitPayment()->deliverTo('Mairie', Address::make('Rue 1', '75001', 'Paris', 'FR'))->build(), 'delivery.address.countryCode'],
    'item from China' => [fn () => specialVatSplitPayment()->line(fn ($l) => $l->name('Sedia')->quantity(1)->netPrice('50.00')->originCountry('CN')->vat(VatCategory::TransferredVatItaly, '22'))->build(), 'line 2.item.originCountry'],
]);

it('does not combine split payment with the standard rate (BR-B-02)', function () {
    $mixed = specialVatSplitPayment()->line(fn ($l) => $l->name('Cancelleria')->quantity(1)->netPrice('10.00')->vat(VatCategory::StandardRate, '22'))->build();
    $charge = specialVatSplitPayment()->charge(VatCategory::StandardRate, '22', amount: '5.00', reason: 'Trasporto')->build();
    $standard = specialVatCalculated(specialVatSplitPayment());
    $breakdown = $standard->with(['vatBreakdown' => [...$standard->vatBreakdown, $standard->vatBreakdown[0]->with(['category' => VatCategory::StandardRate])]]);

    expect(Validate::codes(Validate::data($mixed)))->toContain('BR-B-02')
        ->and(Validate::codes(Validate::data($charge)))->toContain('BR-B-02')
        ->and(Validate::codes(Validate::data($breakdown, calculate: false)))->toContain('BR-B-02')
        ->and(Validate::codes(Validate::data(specialVatSplitPayment()->build())))->not->toContain('BR-B-02')
        ->and(Validate::codes(Validate::data(Quittio\Core\Tests\Support\Invoices::simple()->build())))->not->toContain('BR-B-02')->not->toContain('BR-B-01');
});

it('warns about IGIC with the rate 0 in CII only', function () {
    $zero = specialVatSpanish(VatCategory::CanaryIslands)->line(fn ($l) => $l->name('Libro')->quantity(1)->netPrice('20.00')->vat(VatCategory::CanaryIslands, '0'))->build();
    $messages = array_values(array_filter(Validate::data($zero)->messages, fn ($m) => $m->code === 'QUITTIO-IGIC-ZERO-RATE-CII'));

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->severity)->toBe(Quittio\Core\Validation\Severity::Warning)
        ->and($messages[0]->path)->toBe('line 1.vat.rate')
        ->and(Validate::codes(Validate::data($zero, options: ['syntax' => Syntax::Ubl])))->not->toContain('QUITTIO-IGIC-ZERO-RATE-CII')
        ->and(Validate::codes(Validate::data($zero, 'peppol')))->not->toContain('QUITTIO-IGIC-ZERO-RATE-CII')
        ->and(Validate::codes(Validate::data(specialVatIgic()->build())))->not->toContain('QUITTIO-IGIC-ZERO-RATE-CII')
        ->and(Validate::codes(Validate::data(specialVatSpanish(VatCategory::CeutaMelilla)->line(fn ($l) => $l->name('Pan')->quantity(1)->netPrice('3.00')->vat(VatCategory::CeutaMelilla, '0'))->build())))->not->toContain('QUITTIO-IGIC-ZERO-RATE-CII');
});

it('passes the official validation with IGIC, IPSI and split payment', function (Closure $build, string $syntax) {
    $invoice = specialVatCalculated($build());
    $profile = ProfileRegistry::withDefaults()->get('en16931');
    $xml = $syntax === 'cii' ? (new CiiWriter)->serialize($invoice, $profile) : (new UblWriter)->serialize($invoice, $profile);

    $errors = array_filter(Official::kosit()->validateXml($xml), fn ($m) => $m->isError());

    expect(array_map(fn ($m) => $m->code.': '.$m->message, $errors))->toBe([]);
})->with(function () {
    $invoices = [
        'IGIC' => fn () => specialVatIgic(),
        'IPSI' => fn () => specialVatIpsi(),
        'IPSI 0 %' => fn () => specialVatSpanish(VatCategory::CeutaMelilla)->line(fn ($l) => $l->name('Pan')->quantity(1)->netPrice('3.00')->vat(VatCategory::CeutaMelilla, '0')),
        'split payment' => fn () => specialVatSplitPayment(),
    ];
    foreach ($invoices as $name => $build) {
        yield "{$name} (CII)" => [$build, 'cii'];
        yield "{$name} (UBL)" => [$build, 'ubl'];
    }
    yield 'IGIC 0 % (UBL)' => [fn () => specialVatSpanish(VatCategory::CanaryIslands)->line(fn ($l) => $l->name('Libro')->quantity(1)->netPrice('20.00')->vat(VatCategory::CanaryIslands, '0')), 'ubl'];
})->skip(fn () => ! Official::available(), 'KoSIT validator or Java not available (run tools/schemas/download.sh).')->group('official');

it('documents that the official CII validation rejects IGIC with the rate 0 (QUITTIO-IGIC-ZERO-RATE-CII)', function () {
    $invoice = specialVatCalculated(specialVatSpanish(VatCategory::CanaryIslands)->line(fn ($l) => $l->name('Libro')->quantity(1)->netPrice('20.00')->vat(VatCategory::CanaryIslands, '0')));
    $xml = (new CiiWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get('en16931'));

    $codes = array_map(fn ($m) => $m->code, array_filter(Official::kosit()->validateXml($xml), fn ($m) => $m->isError()));

    expect($codes)->toContain('BR-AF-05');
})->skip(fn () => ! Official::available(), 'KoSIT validator or Java not available (run tools/schemas/download.sh).')->group('official');
