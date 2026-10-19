<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\Meta;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\Syntax;
use Quittio\Country\AT\Rules\UstgRules;
use Quittio\Country\AT\Tax\VatPreset;
use Quittio\Country\AT\Tests\Support\Austrian;

it('accepts a complete Austrian invoice', function () {
    expect(Austrian::codes(Austrian::validate(Austrian::invoice())))->toBe([]);
});

it('requires the complete addresses of seller and buyer', function () {
    $result = Austrian::validate(Austrian::invoice()
        ->seller(fn ($s) => $s->name('Alpen Software GmbH')->vatId('ATU12345678')->address(null, null, 'Wien', 'AT'))
        ->buyer(fn ($b) => $b->name('Tiroler Handel KG')->vatId('ATU87654321')->address('Maria-Theresien-Straße 10', null, null, 'AT')));

    expect(array_map(fn ($m) => [$m->code, $m->bt, $m->path], array_values(array_filter($result->messages, fn ($m) => in_array($m->code, ['AT-USTG-01', 'AT-USTG-02'], true)))))->toBe([
        ['AT-USTG-01', 'BT-35', 'seller.address.line1'],
        ['AT-USTG-01', 'BT-38', 'seller.address.postcode'],
        ['AT-USTG-02', 'BT-53', 'buyer.address.postcode'],
        ['AT-USTG-02', 'BT-52', 'buyer.address.city'],
    ]);
});

it('needs no post code of a buyer abroad and no buyer address on a simplified invoice', function () {
    $abroad = Austrian::invoice()->buyer(fn ($b) => $b->name('Dublin Trading Ltd')->vatId('IE6388047V')->address('1 Main Street', null, 'Dublin', 'IE'));
    $simplified = Austrian::base()
        ->buyer(fn ($b) => $b->name('Barverkauf')->address(null, null, null, 'AT'))
        ->line(fn ($l) => $l->name('Kabel')->quantity(2, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::Standard));

    expect(Austrian::codes(Austrian::validate($abroad)))->not->toContain('AT-USTG-02')
        ->and(Austrian::codes(Austrian::validate($simplified)))->toBe([]);
});

it('treats invoices up to 400 EUR gross and of small businesses as simplified, but not reverse charge', function (Closure $build, bool $simplified) {
    $invoice = (new En16931Calculator)->calculate($build(Austrian::base())->build());

    expect(UstgRules::isSimplifiedInvoice($invoice))->toBe($simplified);
})->with([
    '400.00 gross' => [fn (InvoiceBuilder $b) => $b->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice('333.33')->vat(VatPreset::Standard)), true],
    '400.01 gross' => [fn (InvoiceBuilder $b) => $b->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice('333.34')->vat(VatPreset::Standard)), false],
    'in US dollars' => [fn (InvoiceBuilder $b) => $b->currency('USD')->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice('10.00')->vat(VatPreset::Standard)), false],
    'small business' => [fn (InvoiceBuilder $b) => $b->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice('5000.00')->vat(VatPreset::SmallBusiness)), true],
    'small business flag' => [fn (InvoiceBuilder $b) => $b->flag(Meta::FLAG_SMALL_BUSINESS)->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice('5000.00')->vat(VatCategory::Exempt, '0', 'Kleinunternehmer')), true],
    'reverse charge' => [fn (InvoiceBuilder $b) => $b->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice('10.00')->vat(VatPreset::ReverseChargeDomestic)), false],
    'intra-community supply' => [fn (InvoiceBuilder $b) => $b->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice('10.00')->vat(VatPreset::IntraCommunitySupply)), false],
]);

it('requires the seller UID unless the invoice is simplified or without input tax deduction', function () {
    $withoutUid = fn (InvoiceBuilder $b) => $b->seller(fn ($s) => $s->vatId(null));
    $exempt = $withoutUid(Austrian::base())->line(fn ($l) => $l->name('Arztleistung')->quantity(1, UnitCode::Piece)->netPrice('900.00')->vat(VatPreset::exempt('§ 6 Abs. 1 Z 19 UStG')));
    $simplified = $withoutUid(Austrian::base())->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::Standard));

    expect(Austrian::codes(Austrian::validate($withoutUid(Austrian::invoice()))))->toBe(['AT-USTG-03'])
        ->and(Austrian::codes(Austrian::validate($exempt)))->toBe([])
        ->and(Austrian::codes(Austrian::validate($simplified)))->toBe([]);
});

it('requires the date of the supply, with a warning for prepayment invoices', function () {
    $noDate = Austrian::invoice(null);
    $linePeriods = Austrian::base(null)->line(fn ($l) => $l->name('Wartung')->quantity(1, UnitCode::Month)->netPrice('500.00')->vat(VatPreset::Standard)->period('2026-09-01', '2026-09-30'));
    $prepayment = Austrian::validate(Austrian::invoice(null)->type(InvoiceTypeCode::PrepaymentInvoice));

    expect(Austrian::codes(Austrian::validate($noDate)))->toBe(['AT-USTG-04'])
        ->and(Austrian::codes(Austrian::validate($linePeriods)))->toBe([])
        ->and($prepayment->warnings()[0]->code ?? null)->toBe('AT-USTG-04')
        ->and(Austrian::codes(Austrian::validate(Austrian::base(null)->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice('10.00')->vat(VatPreset::Standard)))))->toBe(['AT-USTG-04']);
});

it('requires the buyer UID above 10,000 EUR gross for a business', function (string $net, bool $consumer, string $currency, array $codes) {
    $builder = Austrian::base()->currency($currency)
        ->buyer(fn ($b) => $b->vatId(null)->consumer($consumer))
        ->line(fn ($l) => $l->name('Server')->quantity(1, UnitCode::Piece)->netPrice($net)->vat(VatPreset::Standard));

    expect(array_values(array_intersect(Austrian::codes(Austrian::validate($builder)), ['AT-USTG-05'])))->toBe($codes);
})->with([
    '10,000.00 gross' => ['8333.33', false, 'EUR', []],
    '10,000.01 gross' => ['8333.34', false, 'EUR', ['AT-USTG-05']],
    'consumer' => ['9000.00', true, 'EUR', []],
    'not in euro' => ['9000.00', false, 'CHF', []],
]);

it('requires the reverse charge statement', function () {
    $own = Austrian::base()->line(fn ($l) => $l->name('Bauleistung')->quantity(1, UnitCode::Piece)->netPrice('2000.00')->vat(VatCategory::ReverseCharge, '0', 'Leistung ohne Umsatzsteuer'));
    $preset = Austrian::base()->line(fn ($l) => $l->name('Bauleistung')->quantity(1, UnitCode::Piece)->netPrice('2000.00')->vat(VatPreset::ReverseChargeDomestic));
    $english = Austrian::base()->line(fn ($l) => $l->name('Construction')->quantity(1, UnitCode::Piece)->netPrice('2000.00')->vat(VatCategory::ReverseCharge, '0', 'Reverse charge'));

    expect(Austrian::codes(Austrian::validate($own)))->toBe(['AT-USTG-06'])
        ->and(Austrian::codes(Austrian::validate($preset)))->toBe([])
        ->and(Austrian::codes(Austrian::validate($english)))->toBe([]);
});

it('requires an exemption note as text', function () {
    $codeOnly = Austrian::base()->buyer(fn ($b) => $b->name('Berlin GmbH')->vatId('DE987654321')->address('Unter den Linden 1', '10117', 'Berlin', 'DE'))
        ->line(fn ($l) => $l->name('Maschine')->quantity(1, UnitCode::Piece)->netPrice('2000.00')->vat(VatCategory::IntraCommunitySupply, '0', null, 'VATEX-EU-IC'));
    $preset = Austrian::base()->buyer(fn ($b) => $b->name('Berlin GmbH')->vatId('DE987654321')->address('Unter den Linden 1', '10117', 'Berlin', 'DE'))
        ->line(fn ($l) => $l->name('Maschine')->quantity(1, UnitCode::Piece)->netPrice('2000.00')->vat(VatPreset::IntraCommunitySupply));

    expect(Austrian::codes(Austrian::validate($codeOnly)))->toBe(['AT-USTG-07'])
        ->and(Austrian::codes(Austrian::validate($preset)))->toBe([]);
});

it('forbids VAT on small business invoices', function () {
    $taxed = Austrian::invoice()->flag(Meta::FLAG_SMALL_BUSINESS);
    $exempt = Austrian::base()->line(fn ($l) => $l->name('Grafik')->quantity(1, UnitCode::Piece)->netPrice('300.00')->vat(VatPreset::SmallBusiness));

    expect(Austrian::codes(Austrian::validate($taxed)))->toBe(['AT-USTG-08'])
        ->and(Austrian::codes(Austrian::validate($exempt)))->toBe([]);
});

it('requires the label "Gutschrift" on self-billed invoices, as a warning in XML', function () {
    $selfBilled = Austrian::invoice()->type(InvoiceTypeCode::SelfBilledInvoice);

    expect(Austrian::validate($selfBilled)->errors()[0]->code ?? null)->toBe('AT-USTG-09')
        ->and(Austrian::validate($selfBilled, syntax: Syntax::Cii)->warnings()[0]->code ?? null)->toBe('AT-USTG-09')
        ->and(Austrian::codes(Austrian::validate($selfBilled->note('Gutschrift'))))->toBe([]);
});

it('warns when an invoice in another currency does not state the VAT in euro', function () {
    $dollars = Austrian::invoice()->currency('USD');

    expect(Austrian::codes(Austrian::validate($dollars)))->toBe(['AT-USTG-10'])
        ->and(Austrian::codes(Austrian::validate(Austrian::invoice()->currency('USD')->taxCurrency('EUR', '0.92'))))->toBe([]);
});

it('warns about invoices issued more than six months after the supply', function () {
    $late = Austrian::invoice('2026-04-04');

    expect(Austrian::validate($late)->warnings()[0]->message ?? null)->toContain('2026-04-04')
        ->and(Austrian::codes(Austrian::validate(Austrian::invoice('2026-04-05'))))->toBe([])
        ->and(Austrian::codes(Austrian::validate($late->buyer(fn ($b) => $b->name('Maria Muster')->address('Ring 1', '1010', 'Wien', 'AT')->consumer()))))->toBe([]);
});

it('warns about services in another member state invoiced after the 15th of the following month', function (string $issueDate, array $codes) {
    $builder = Austrian::base()->issueDate($issueDate)
        ->buyer(fn ($b) => $b->name('Berlin GmbH')->vatId('DE987654321')->address('Unter den Linden 1', '10117', 'Berlin', 'DE'))
        ->line(fn ($l) => $l->name('Beratung')->quantity(10, UnitCode::Hour)->netPrice('120.00')->vat(VatPreset::ReverseChargeEu));

    expect(Austrian::codes(Austrian::validate($builder)))->toBe($codes);
})->with([
    'on the 15th' => ['2026-10-15', []],
    'on the 16th' => ['2026-10-16', ['AT-USTG-12']],
]);

it('writes the messages in German', function () {
    $result = Austrian::validate(Austrian::invoice(null), locale: 'de');

    expect($result->errors()[0]->message)->toBe('Der Tag der Lieferung oder sonstigen Leistung muss angegeben sein: Lieferdatum (BT-72), Abrechnungszeitraum (BG-14) oder Zeiträume an allen Positionen (BG-26) (§ 11 Abs. 1 Z 3 lit. d UStG).');
});
