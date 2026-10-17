<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Codes\CodeList;
use Quittio\Core\Codes\CodeListGenerator;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\MimeType;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Support\Date;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Official;
use Quittio\Core\Tests\Support\Validate;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Xml\Ubl\UblWriter;

/*
 * The Peppol BIS Billing 3.0 rules of the PHP driver (PeppolRules). Each rule is shown violated
 * and satisfied; the official group checks with the KoSIT validator that the official Peppol rules
 * agree and that the UBL writer satisfies the rules that need no PHP rule.
 */

function peppolBuilder(): InvoiceBuilder
{
    return Scenarios::make('peppol')->buyerReference('PO-1');
}

function peppolParticipants(InvoiceBuilder $builder): InvoiceBuilder
{
    return $builder->buyerReference('PO-1')
        ->seller(fn ($s) => $s->electronicAddress('DE123456789', '9930'))
        ->buyer(fn ($b) => $b->electronicAddress('4000001000005', '0088'));
}

function peppolLine(InvoiceBuilder $builder, callable $line): InvoiceBuilder
{
    return $builder->line(fn ($l) => $line($l->name('Support')->quantity(1, UnitCode::Hour)->vat(VatCategory::ReverseCharge, '0', 'Reverse charge', 'VATEX-EU-AE')));
}

/**
 * @return array<string, array{0: Closure(): InvoiceBuilder, 1: string, 2: Closure(): InvoiceBuilder}>
 */
function peppolRuleCases(): array
{
    $cases = [
        'R007 business process format' => [
            fn () => peppolBuilder()->businessProcess('urn:fdc:peppol.eu:2017:poacc:billing:1:1.0'),
            'PEPPOL-EN16931-R007',
            fn () => peppolBuilder()->businessProcess('urn:fdc:peppol.eu:2017:poacc:billing:02:1.0'),
        ],
        'R008 value of only whitespace' => [
            fn () => peppolBuilder()->purchaseOrderReference('PO-2')->buyerReference(' '),
            'PEPPOL-EN16931-R008',
            fn () => peppolBuilder()->purchaseOrderReference('PO-2')->buyerReference(null),
        ],
        'R046 net price not gross price minus discount' => [
            fn () => peppolLine(peppolBuilder(), fn ($l) => $l->netPrice('90.00')->listPrice('100.00', '5.00')),
            'PEPPOL-EN16931-R046',
            fn () => peppolLine(peppolBuilder(), fn ($l) => $l->netPrice('95.00')->listPrice('100.00', '5.00')),
        ],
        'R055 VAT totals with different signs' => [
            fn () => peppolParticipants(Scenarios::make('foreign-currency'))->exchangeRate('-0.912345'),
            'PEPPOL-EN16931-R055',
            fn () => peppolParticipants(Scenarios::make('foreign-currency')),
        ],
        'R110 line period starts before the invoicing period' => [
            fn () => peppolLine(peppolBuilder()->invoicingPeriod('2026-10-01', '2026-10-31'), fn ($l) => $l->netPrice('10.00')->period('2026-09-30', '2026-10-31')),
            'PEPPOL-EN16931-R110',
            fn () => peppolLine(peppolBuilder()->invoicingPeriod('2026-10-01', '2026-10-31'), fn ($l) => $l->netPrice('10.00')->period('2026-10-01', '2026-10-31')),
        ],
        'R111 line period ends after the invoicing period' => [
            fn () => peppolLine(peppolBuilder()->invoicingPeriod('2026-10-01', '2026-10-31'), fn ($l) => $l->netPrice('10.00')->period('2026-10-01', '2026-11-01')),
            'PEPPOL-EN16931-R111',
            fn () => peppolLine(peppolBuilder()->invoicingPeriod('2026-10-01', '2026-10-31'), fn ($l) => $l->netPrice('10.00')->period('2026-10-15', '2026-10-31')),
        ],
        'F001 date with more than four year digits' => [
            fn () => peppolBuilder()->deliveryDate(Date::of(10000, 1, 1)),
            'PEPPOL-EN16931-F001',
            fn () => peppolBuilder()->deliveryDate('2026-10-01'),
        ],
        'P0100 invoice type code of another process' => [
            fn () => peppolBuilder()->type(InvoiceTypeCode::SelfBilledInvoice),
            'PEPPOL-EN16931-P0100',
            fn () => peppolBuilder()->type(InvoiceTypeCode::SelfBilledInvoice)->businessProcess('urn:fdc:peppol.eu:2017:poacc:billing:02:1.0'),
        ],
        'P0101 credit note type code of another process' => [
            fn () => peppolBuilder()->type(InvoiceTypeCode::SelfBilledCreditNote)->precedingInvoice('RE-2026-0001', '2026-09-01'),
            'PEPPOL-EN16931-P0101',
            fn () => peppolBuilder()->type(InvoiceTypeCode::CreditNote)->precedingInvoice('RE-2026-0001', '2026-09-01'),
        ],
        'P0112 corrected invoice outside Germany' => [
            fn () => peppolBuilder()->type(InvoiceTypeCode::CorrectedInvoice)->precedingInvoice('RE-2026-0001', '2026-09-01'),
            'PEPPOL-EN16931-P0112',
            fn () => peppolParticipants(Scenarios::make('simple'))->type(InvoiceTypeCode::CorrectedInvoice)->precedingInvoice('RE-2026-0001', '2026-09-01'),
        ],
    ];

    $exemptions = [
        'P0104' => ['VATEX-EU-G', VatCategory::Exempt, VatCategory::Export],
        'P0105' => ['VATEX-EU-O', VatCategory::Exempt, VatCategory::NotSubjectToVat],
        'P0106' => ['VATEX-EU-IC', VatCategory::Exempt, VatCategory::IntraCommunitySupply],
        'P0107' => ['VATEX-EU-AE', VatCategory::Exempt, VatCategory::ReverseCharge],
        'P0108' => ['VATEX-EU-D', VatCategory::ReverseCharge, VatCategory::Exempt],
        'P0109' => ['VATEX-EU-F', VatCategory::ReverseCharge, VatCategory::Exempt],
        'P0110' => ['VATEX-EU-I', VatCategory::ReverseCharge, VatCategory::Exempt],
        'P0111' => ['vatex-eu-j', VatCategory::ReverseCharge, VatCategory::Exempt],
    ];
    foreach ($exemptions as $id => [$code, $wrong, $right]) {
        $line = fn (VatCategory $category) => fn () => peppolParticipants(Scenarios::base())
            ->line(fn ($l) => $l->name('Item')->quantity(1, UnitCode::Piece)->netPrice('10.00')->vat($category, '0', 'Exemption reason', $code));
        $cases["{$id} {$code} with category {$wrong->value}"] = [$line($wrong), "PEPPOL-EN16931-{$id}", $line($right)];
    }

    // [scheme, valid value, invalid value]
    $identifiers = [
        'R040' => ['0088', '4000001000005', '4000001000006'],
        'R041' => ['0192', '987654325', '987654321'],
        'R042' => ['0184', 'DK12345678', 'DK1234567'],
        'R043' => ['0208', '0123456749', '0123456748'],
        'R044' => ['0201', 'ABC123', 'ABC12'],
        'R045' => ['0210', 'RSSMRA85T10A562S', 'RSSMRA85T10A56'],
        'R047' => ['0211', 'IT00743110157', 'IT00743110158'],
        'R049' => ['0007', '5560360793', '5560360794'],
        'R050' => ['0151', '51824753556', '51824753557'],
        'R052' => ['0096', '1234567890', '123456789'],
        'R053' => ['0198', 'DK12345678', '12345678'],
    ];
    foreach ($identifiers as $id => [$scheme, $valid, $invalid]) {
        $cases["COMMON-{$id} scheme {$scheme}"] = [
            fn () => peppolBuilder()->seller(fn ($s) => $s->identifier($invalid, $scheme)),
            "PEPPOL-COMMON-{$id}",
            fn () => peppolBuilder()->seller(fn ($s) => $s->identifier($valid, $scheme)),
        ];
    }
    $cases['COMMON-R046 scheme 9907'] = [
        fn () => peppolBuilder()->buyer(fn ($b) => $b->electronicAddress('RSSMRA85T10A56', '9907')),
        'PEPPOL-COMMON-R046',
        fn () => peppolBuilder()->buyer(fn ($b) => $b->electronicAddress('RSSMRA85T10A562S', '9907')),
    ];

    return $cases;
}

it('reports each Peppol rule only in the Peppol profile', function (Closure $violating, string $code, Closure $satisfying) {
    $invoice = $violating()->build();

    expect(Validate::codes(Validate::data($invoice, 'peppol')))->toContain($code)
        ->and(Validate::codes(Validate::data($invoice)))->not->toContain($code)
        ->and(Validate::codes(Validate::data($satisfying()->build(), 'peppol')))->not->toContain($code);
})->with(peppolRuleCases());

it('reports the Peppol rules with the severity of the Schematron', function () {
    $severity = fn (InvoiceBuilder $builder, string $code) => array_values(array_unique(array_map(
        fn ($m) => $m->severity,
        array_filter(Validate::data($builder->build(), 'peppol')->messages, fn ($m) => $m->code === $code),
    ), SORT_REGULAR));

    expect($severity(peppolBuilder()->seller(fn ($s) => $s->identifier('ABC12', '0201')), 'PEPPOL-COMMON-R044'))->toBe([Severity::Warning])
        ->and($severity(peppolBuilder()->seller(fn ($s) => $s->identifier('123', '0096')), 'PEPPOL-COMMON-R052'))->toBe([Severity::Warning])
        ->and($severity(peppolBuilder()->seller(fn ($s) => $s->identifier('4000001000006', '0088')), 'PEPPOL-COMMON-R040'))->toBe([Severity::Error])
        ->and($severity(peppolBuilder()->type(InvoiceTypeCode::SelfBilledInvoice), 'PEPPOL-EN16931-P0100'))->toBe([Severity::Error]);
});

it('checks identifier schemes wherever an electronic address, a party identifier or a legal registration uses them', function () {
    $invoice = peppolBuilder()
        ->buyer(fn ($b) => $b->electronicAddress('4000001000006', '0088')->legalRegistration('4000001000006', '0088'))
        ->seller(fn ($s) => $s->identifier('4000001000006', '0088')->legalRegistration('4000001000006', '0088'))
        ->payee('Factoring AG', '4000001000006', '4000001000006', '0088', '0088')
        ->deliverTo('Warehouse', null, Identifier::of('4000001000006', '0088'))
        ->build();
    $invoice = $invoice->with(['buyer' => $invoice->buyer->with(['identifier' => Identifier::of('4000001000006', '0088')])]);
    $messages = array_filter(Validate::data($invoice, 'peppol')->messages, fn ($m) => $m->code === 'PEPPOL-COMMON-R040');

    // The delivery location (cac:DeliveryLocation/cbc:ID) is not checked by the Schematron.
    expect(array_values(array_map(fn ($m) => $m->path, $messages)))->toEqualCanonicalizing([
        'buyer.electronicAddress.value', 'seller.identifiers[0].value', 'seller.legalRegistration.value',
        'buyer.identifier.value', 'buyer.legalRegistration.value', 'payee.identifier.value', 'payee.legalRegistration.value',
    ])->and(array_values($messages)[0]->message)->toContain('"4000001000006"');
});

it('checks the Italian tax code of scheme 9907 only in electronic addresses', function () {
    $invoice = peppolBuilder()->seller(fn ($s) => $s->identifier('XYZ', '9907'))->build();

    expect(Validate::codes(Validate::data($invoice, 'peppol')))->not->toContain('PEPPOL-COMMON-R046');
});

it('accepts the alternative formats of the Danish, Italian and Swedish identifiers', function (string $scheme, string $value) {
    $invoice = peppolBuilder()->seller(fn ($s) => $s->identifier($value, $scheme))->build();

    expect(array_filter(Validate::codes(Validate::data($invoice, 'peppol')), fn ($c) => str_starts_with($c, 'PEPPOL-COMMON')))->toBe([]);
})->with([
    'CVR without DK' => ['0184', '12345678'],
    'codice fiscale with eleven digits' => ['0210', '12345678901'],
    'partita IVA without the IT prefix (not checked)' => ['0211', '12345678901'],
]);

it('reports values of only whitespace that would become empty elements, but not mandatory values', function () {
    $invoice = peppolLine(peppolBuilder()->note(' ')->seller(fn ($s) => $s->tradingName("\t")->address(' ', '12345', 'Musterstadt', 'DE')), fn ($l) => $l->netPrice('10.00')->note(' '))
        ->number(' ')
        ->build();
    $result = Validate::data($invoice, 'peppol');
    $paths = array_values(array_map(fn ($m) => $m->path, array_filter($result->messages, fn ($m) => $m->code === 'PEPPOL-EN16931-R008')));

    expect($paths)->toEqualCanonicalizing(['notes[0].content', 'seller.tradingName', 'seller.address.line1', 'line 2.note'])
        ->and(Validate::codes($result))->toContain('BR-02');
});

it('reports a corrected invoice between German organisations only with both addresses in Germany', function () {
    $invoice = peppolParticipants(Scenarios::make('simple'))->type(InvoiceTypeCode::CorrectedInvoice)->precedingInvoice('RE-2026-0001', '2026-09-01');

    expect(Validate::codes(Validate::data($invoice->build(), 'peppol')))->not->toContain('PEPPOL-EN16931-P0112')
        ->and(Validate::codes(Validate::data($invoice->buyer(fn ($b) => $b->address('Kerkstraat 1', '1000', 'Brussel', 'BE'))->build(), 'peppol')))->toContain('PEPPOL-EN16931-P0112');
});

it('leaves codes outside the EN 16931 lists to the EN 16931 code list rules', function () {
    $type = peppolBuilder()->type('999')->build();
    $currency = peppolBuilder()->build()->with(['currency' => 'XYZ']);

    expect(Validate::codes(Validate::data($type, 'peppol')))->toContain('BR-CL-01')->not->toContain('PEPPOL-EN16931-P0100')
        ->and(Validate::codes(Validate::data($currency, 'peppol', calculate: false)))->toContain('BR-CL-04')->not->toContain('PEPPOL-EN16931-CL007');
});

it('reports currencies of the EN 16931 list that the Peppol rules do not know', function () {
    $invoice = peppolBuilder()->build()->with(['currency' => 'XCG']);
    $messages = array_values(array_filter(Validate::data($invoice, 'peppol', calculate: false)->messages, fn ($m) => $m->code === 'PEPPOL-EN16931-CL007'));

    expect(CodeList::contains(CodeList::CURRENCIES, 'XCG'))->toBeTrue()
        ->and(CodeList::contains(CodeList::PEPPOL_CURRENCIES, 'XCG'))->toBeFalse()
        ->and(array_map(fn ($m) => $m->path, $messages))->toBe(['currency'])
        ->and(Validate::codes(Validate::data($invoice, calculate: false)))->not->toContain('PEPPOL-EN16931-CL007');
});

it('keeps the Peppol document type codes consistent with the UBL document the writer chooses', function () {
    $invoices = CodeList::codes(CodeList::PEPPOL_INVOICE_TYPES);
    $creditNotes = CodeList::codes(CodeList::PEPPOL_CREDIT_NOTE_TYPES);

    expect(array_diff([...$invoices, ...$creditNotes], CodeList::codes(CodeList::INVOICE_TYPES)))->toBe([])
        ->and(array_filter($invoices, fn (string $code) => InvoiceTypeCode::from($code)->isCreditNote()))->toBe([])
        ->and(array_filter($creditNotes, fn (string $code) => ! InvoiceTypeCode::from($code)->isCreditNote()))->toBe([]);
});

it('generates the Peppol code lists from the Schematron as shipped', function () {
    $target = sys_get_temp_dir().'/quittio-peppol-codes-'.bin2hex(random_bytes(4));
    mkdir($target);

    $counts = (new CodeListGenerator)->generatePeppol(Official::root().'/peppol/PEPPOL-EN16931-UBL.sch', $target);

    expect(array_keys($counts))->toBe(array_keys(CodeListGenerator::PEPPOL_LISTS));
    foreach (array_keys(CodeListGenerator::PEPPOL_LISTS) as $name) {
        expect(file_get_contents($target.'/'.$name.'.json'))->toBe(file_get_contents(CodeListGenerator::defaultTarget().'/'.$name.'.json'));
    }
})->group('official')->skip(fn () => ! is_file(Official::root().'/peppol/PEPPOL-EN16931-UBL.sch'), 'The Peppol Schematron is not available (run tools/schemas/download.sh).');

it('needs no PHP rule for Peppol code lists that equal the EN 16931 lists', function () {
    $schematron = (string) file_get_contents(Official::root().'/peppol/PEPPOL-EN16931-UBL.sch');
    $list = function (string $name) use ($schematron): array {
        preg_match('/<let name="'.$name.'" value="tokenize\(\'([^\']*)\'/', $schematron, $match);
        $codes = preg_split('/\s+/', trim($match[1] ?? '')) ?: [];
        sort($codes);

        return $codes;
    };
    $codes = function (string $list): array {
        $codes = CodeList::codes($list);
        sort($codes);

        return $codes;
    };

    // PEPPOL-EN16931-CL001 (BR-CL-24, MimeType), CL002 (BR-CL-19), CL003 (BR-CL-20), CL006 (BR-CL-06).
    expect($list('MIMECODE'))->toBe($codes(CodeList::MIME_TYPES))
        ->and(array_map(fn (MimeType $type) => $type->value, MimeType::cases()))->toEqualCanonicalizing($codes(CodeList::MIME_TYPES))
        ->and($list('UNCL5189'))->toBe($codes(CodeList::ALLOWANCE_REASONS))
        ->and($list('UNCL7161'))->toBe($codes(CodeList::CHARGE_REASONS))
        ->and($list('UNCL2005'))->toBe(['3', '35', '432']);
})->group('official')->skip(fn () => ! is_file(Official::root().'/peppol/PEPPOL-EN16931-UBL.sch'), 'The Peppol Schematron is not available (run tools/schemas/download.sh).');

/*
 * The rules the UBL writer always satisfies (R004, R043, R044, R051, R053, R054, R080, R100, R101,
 * CL001): an invoice and a credit note with every structure they concern pass the official Peppol rules.
 */
it('writes Peppol documents that pass the official Peppol rules', function (InvoiceTypeCode $type) {
    $builder = peppolParticipants(Scenarios::make('foreign-currency'))
        ->type($type)
        ->precedingInvoice('RE-2026-0001', '2026-09-01')
        ->projectReference('PRJ-7', 'Rollout')
        ->invoicedObject('METER-4711', 'AAG')
        ->contractReference('C-2026-01')
        ->invoicingPeriod('2026-09-01', '2026-09-30')
        ->attachment('ATT-1', '%PDF-1.4 test', MimeType::Pdf, 'timesheet.pdf', 'Timesheet')
        ->allowance(VatCategory::StandardRate, '19', '10.00', reason: 'Loyalty', reasonCode: '95')
        ->charge(VatCategory::StandardRate, '19', '5.00', reason: 'Packing', reasonCode: 'ABL')
        ->line(fn ($l) => $l->name('Maintenance')->quantity(2, UnitCode::Hour)->listPrice('120.00', '20.00')
            ->period('2026-09-01', '2026-09-15')->objectId('METER-4712', 'AAG')
            ->allowance('5.00', reason: 'Discount', reasonCode: '95')->charge('2.00', reason: 'Travel', reasonCode: 'ABL')
            ->vat(VatCategory::StandardRate, '19'));
    $invoice = (new En16931Calculator)->calculate($builder->build());
    $xml = (new UblWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get('peppol'));

    $php = array_filter(Validate::data($invoice, 'peppol', calculate: false)->messages, fn ($m) => $m->isError());
    $official = array_filter(Official::kosit()->validateXml($xml), fn ($m) => $m->isError() || str_starts_with($m->code, 'PEPPOL'));

    expect($xml)->toContain($type === InvoiceTypeCode::CreditNote ? '<cbc:DocumentTypeCode>50</cbc:DocumentTypeCode>' : '<cac:ProjectReference>')
        ->and($xml)->toContain('<cbc:DocumentTypeCode>130</cbc:DocumentTypeCode>')
        ->and($xml)->toContain('<cbc:TaxCurrencyCode>EUR</cbc:TaxCurrencyCode>')
        ->and($xml)->toContain('mimeCode="application/pdf"')
        ->and($xml)->toContain('<cbc:BaseAmount currencyID="USD">120.00</cbc:BaseAmount>')
        ->and(array_map(fn ($m) => $m->code.': '.$m->message, $php))->toBe([])
        ->and(array_map(fn ($m) => $m->code.': '.$m->message, $official))->toBe([]);
})->with([
    'invoice' => [InvoiceTypeCode::CommercialInvoice],
    'credit note' => [InvoiceTypeCode::CreditNote],
])->group('official')->skip(fn () => ! Official::available() || ! is_file(Official::root().'/peppol/PEPPOL-EN16931-UBL.xsl'), 'KoSIT validator or Peppol artifacts are not available (run tools/schemas/download.sh).');

it('agrees with the official Peppol rules on every violation and its correction', function () {
    $documents = [];
    $expected = [];
    foreach (peppolRuleCases() as $name => [$violating, $code, $satisfying]) {
        foreach (['violated' => $violating, 'satisfied' => $satisfying] as $variant => $build) {
            $invoice = (new En16931Calculator)->calculate($build()->build());
            $documents["{$name} ({$variant})"] = (new UblWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get('peppol'));
            $php = array_values(array_unique(array_map(fn ($m) => $m->code, array_filter(Validate::data($invoice, 'peppol', calculate: false)->messages, fn ($m) => str_starts_with($m->code, 'PEPPOL')))));
            sort($php);
            $expected["{$name} ({$variant})"] = $php;
        }
        expect($expected["{$name} (violated)"])->toContain($code);
    }

    $official = [];
    foreach (Official::kositAll($documents) as $name => $messages) {
        $codes = array_values(array_unique(array_map(fn ($m) => $m->code, array_filter($messages, fn ($m) => str_starts_with($m->code, 'PEPPOL')))));
        sort($codes);
        $official[$name] = $codes;
    }

    expect($official)->toBe($expected);
})->group('official')->skip(fn () => ! Official::available() || ! is_file(Official::root().'/peppol/PEPPOL-EN16931-UBL.xsl'), 'KoSIT validator or Peppol artifacts are not available (run tools/schemas/download.sh).');
