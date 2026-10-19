<?php

declare(strict_types=1);

use Brick\Money\Money;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Codes\CodeList;
use Quittio\Core\Codes\CodeListGenerator;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\MimeType;
use Quittio\Core\Enums\PaymentMeansCode;
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
        'P0112 partial invoice outside Germany' => [
            fn () => peppolBuilder()->type(InvoiceTypeCode::PartialInvoice),
            'PEPPOL-EN16931-P0112',
            fn () => peppolParticipants(Scenarios::make('simple'))->type(InvoiceTypeCode::PartialInvoice),
        ],
        'R001 empty business process' => [
            fn () => peppolBuilder()->businessProcess(''),
            'PEPPOL-EN16931-R001',
            fn () => peppolBuilder()->businessProcess(null),
        ],
        'R007 business process of only whitespace' => [
            fn () => peppolBuilder()->businessProcess(' '),
            'PEPPOL-EN16931-R007',
            fn () => peppolBuilder()->businessProcess(null),
        ],
        'R002 two notes outside Germany' => [
            fn () => peppolBuilder()->note('First')->note('Second'),
            'PEPPOL-EN16931-R002',
            fn () => peppolParticipants(Scenarios::make('simple'))->note('First')->note('Second'),
        ],
        'R003 neither buyer reference nor purchase order reference' => [
            fn () => peppolBuilder()->buyerReference(null),
            'PEPPOL-EN16931-R003',
            fn () => peppolBuilder()->buyerReference(null)->purchaseOrderReference('PO-2'),
        ],
        'CL008 electronic address by e-mail' => [
            fn () => peppolBuilder()->buyer(fn ($b) => $b->electronicAddress('ap@klant.be', 'EM')),
            'PEPPOL-EN16931-CL008',
            fn () => peppolBuilder()->buyer(fn ($b) => $b->electronicAddress('0123456749', '0208')),
        ],
        'CL007 accounting currency unknown to Peppol' => [
            fn () => peppolBuilder()->taxCurrency('XCG', '1.1'),
            'PEPPOL-EN16931-CL007',
            fn () => peppolBuilder()->taxCurrency('USD', '1.1'),
        ],
        'R061 direct debit without mandate reference' => [
            fn () => peppolBuilder()->payment(PaymentMeansCode::SepaDirectDebit)->directDebit(null, 'DE98ZZZ09999999999', 'DE02120300000000202051'),
            'PEPPOL-EN16931-R061',
            fn () => peppolBuilder()->payment(PaymentMeansCode::SepaDirectDebit)->directDebit('M-1', 'DE98ZZZ09999999999', 'DE02120300000000202051'),
        ],
        'R121 base quantity of zero' => [
            fn () => peppolLine(peppolBuilder(), fn ($l) => $l->quantity(1, UnitCode::Piece)->netPrice('10.00', 0)),
            'PEPPOL-EN16931-R121',
            fn () => peppolLine(peppolBuilder(), fn ($l) => $l->quantity(1, UnitCode::Piece)->netPrice('10.00', 1)),
        ],
        'R130 base quantity unit other than the quantity unit' => [
            fn () => peppolLine(peppolBuilder(), fn ($l) => $l->quantity(1, UnitCode::Piece)->netPrice('10.00', 1, UnitCode::Hour)),
            'PEPPOL-EN16931-R130',
            fn () => peppolLine(peppolBuilder(), fn ($l) => $l->quantity(1, UnitCode::Piece)->netPrice('10.00', 1, UnitCode::Piece)),
        ],
    ];

    // [document level allowance or charge, amount, percent, base amount]
    $allowancesAndCharges = [
        'R040 allowance amount not base × percentage' => ['allowance', '11.00', '1', '1000.00', 'PEPPOL-EN16931-R040', '10.00'],
        'R040 charge amount not base × percentage' => ['charge', '21.00', '2', '1000.00', 'PEPPOL-EN16931-R040', '20.00'],
        'R041 allowance percentage without base amount' => ['allowance', '10.00', '1', null, 'PEPPOL-EN16931-R041', null],
        'R041 charge percentage without base amount' => ['charge', '10.00', '1', null, 'PEPPOL-EN16931-R041', null],
        'R042 allowance base amount without percentage' => ['allowance', '10.00', null, '1000.00', 'PEPPOL-EN16931-R042', null],
        'R042 charge base amount without percentage' => ['charge', '10.00', null, '1000.00', 'PEPPOL-EN16931-R042', null],
    ];
    foreach ($allowancesAndCharges as $name => [$kind, $amount, $percent, $base, $code, $correct]) {
        $entry = fn (string $amount, ?string $percent, ?string $base) => fn () => peppolBuilder()->{$kind}(
            VatCategory::ReverseCharge, '0', amount: $amount, percent: $percent, reason: 'Agreed', reasonCode: $kind === 'charge' ? 'FC' : '95',
            baseAmount: $base, exemptionReason: 'Reverse charge', exemptionReasonCode: 'VATEX-EU-AE',
        );
        $cases[$name] = [$entry($amount, $percent, $base), $code, $correct === null ? $entry($amount, '1', '1000.00') : $entry($correct, $percent, $base)];
    }

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
    $messages = array_filter(Validate::data($invoice, 'peppol')->messages, fn ($m) => $m->code === $code);

    expect(Validate::codes(Validate::data($invoice, 'peppol')))->toContain($code)
        // Every placeholder of the message is replaced.
        ->and(array_filter($messages, fn ($m) => preg_match('/:(value|line|field|bt|category)\b/', $m->message) === 1))->toBe([])
        ->and(Validate::codes(Validate::data($invoice)))->not->toContain($code)
        ->and(Validate::codes(Validate::data($satisfying()->build(), 'peppol')))->not->toContain($code);
})->with(peppolRuleCases());

/**
 * A Peppol invoice with every field the Peppol rules check; $text maps each optional text value.
 */
function peppolComplete(Closure $text): InvoiceBuilder
{
    $address = fn (string $country, string $prefix) => new Address(
        countryCode: $country, line1: $text($prefix.' 1'), postcode: $text('12345'), city: $text($prefix.'stadt'),
        line2: $text('Hof'), line3: $text('Etage 2'), subdivision: $text('BY'),
    );

    return peppolBuilder()
        ->note($text('Thank you for your order.'))
        ->projectReference($text('PRJ-1'), 'Project')
        ->contractReference($text('C-1'))
        ->purchaseOrderReference($text('PO-9'))
        ->salesOrderReference($text('SO-1'))
        ->receivingAdviceReference($text('RA-1'))
        ->despatchAdviceReference($text('DA-1'))
        ->tenderReference($text('T-1'))
        ->invoicedObject($text('OBJ-1'), 'AAA')
        ->buyerAccountingReference($text('ACC-1'))
        ->invoicingPeriod('2026-09-01', '2026-09-30')
        ->seller(fn ($s) => $s->tradingName($text('Muster'))->identifier($text('4000001000005'), '0088')->legalRegistration($text('HRB 1'))
            ->taxNumber($text('12/345/67890'))->additionalLegalInfo($text('Amtsgericht Musterstadt'))
            ->postalAddress($address('DE', 'Haupt'))->contact($text('Max Muster'), $text('+49 123'), $text('max@muster.de')))
        ->buyer(fn ($b) => $b->tradingName($text('Klant'))->legalRegistration($text('0123456749'), '0208')
            ->postalAddress($address('BE', 'Kerk'))->contact($text('Jan Klant'), $text('+32 123'), $text('jan@klant.be')))
        ->payee('Factoring AG', $text('4000001000005'), $text('0123456749'), '0088', '0208')
        ->taxRepresentative('Vertreter GmbH', 'DE111111111', $address('DE', 'Vertreter'))
        ->deliverTo($text('Lager'), $address('DE', 'Lager'), Identifier::of($text('4000001000005'), '0088'))
        ->allowance(VatCategory::ReverseCharge, '0', amount: '10.00', percent: '1', reason: $text('Discount'), reasonCode: '95', baseAmount: '1000.00', exemptionReason: 'Reverse charge', exemptionReasonCode: 'VATEX-EU-AE')
        ->charge(VatCategory::ReverseCharge, '0', amount: '20.00', percent: '2', reason: $text('Freight'), reasonCode: 'FC', baseAmount: '1000.00', exemptionReason: 'Reverse charge', exemptionReasonCode: 'VATEX-EU-AE')
        ->attachment('A-1', null, null, null, $text('Timesheet'), 'https://example.com/timesheet')
        ->line(fn ($l) => $l->name('Licence')->description($text('Annual licence'))->note($text('Renewal'))->quantity(2, UnitCode::Piece)
            ->netPrice('45.00', 1, UnitCode::Piece)->listPrice('50.00', '5.00')->period('2026-09-01', '2026-09-30')
            ->sellerId($text('S-1'))->buyerId($text('B-1'))->standardId($text('4000001000005'))->classification($text('43232408'), 'STI')
            ->orderLineReference($text('1'))->buyerAccountingReference($text('ACC-2'))->objectId($text('OBJ-2'), 'AAA')
            ->allowance('1.00', reason: $text('Line discount'), reasonCode: '95')->charge('2.00', reason: $text('Line freight'), reasonCode: 'FC')
            ->vat(VatCategory::ReverseCharge, '0', 'Reverse charge', 'VATEX-EU-AE'));
}

it('reports nothing for a Peppol invoice with every field the Peppol rules check', function () {
    $invoice = peppolComplete(fn (string $value) => $value)
        ->seller(fn ($s) => $s->identifier('987654325', '0192')->identifier('DK12345678', '0184')->identifier('0123456749', '0208')
            ->identifier('ABC123', '0201')->identifier('RSSMRA85T10A562S', '0210')->identifier('IT00743110157', '0211')
            ->identifier('5560360793', '0007')->identifier('51824753556', '0151')->identifier('1234567890', '0096')->identifier('DK12345678', '0198'))
        ->directDebit('M-1', 'DE98ZZZ09999999999', 'DE02120300000000202051')
        ->build();

    expect(Validate::codes(Validate::data($invoice, 'peppol')))->toBe([]);
});

it('reports every optional text of only whitespace with its business term and path', function (Closure $builder, array $expected) {
    $messages = array_filter(Validate::data($builder()->build(), 'peppol')->messages, fn ($m) => $m->code === 'PEPPOL-EN16931-R008');

    expect(array_values(array_map(fn ($m) => [$m->bt, $m->path], $messages)))->toEqualCanonicalizing($expected)
        ->and(array_values($messages)[0]->message)->toBe("The value of {$expected[0][1]} ({$expected[0][0]}) contains only whitespace. Peppol does not allow empty elements.");
})->with([
    'references, parties, delivery, allowances, attachments and lines' => [
        fn () => peppolComplete(fn (string $value) => ' '),
        [
            ['BT-11', 'projectReference.id'], ['BT-12', 'contractReference'], ['BT-13', 'purchaseOrderReference'], ['BT-14', 'salesOrderReference'],
            ['BT-15', 'receivingAdviceReference'], ['BT-16', 'despatchAdviceReference'], ['BT-17', 'tenderReference'], ['BT-18', 'invoicedObject.value'],
            ['BT-19', 'buyerAccountingReference'], ['BT-28', 'seller.tradingName'], ['BT-30', 'seller.legalRegistration.value'], ['BT-32', 'seller.taxNumber'],
            ['BT-33', 'seller.additionalLegalInfo'], ['BT-35', 'seller.address.line1'], ['BT-36', 'seller.address.line2'], ['BT-162', 'seller.address.line3'],
            ['BT-37', 'seller.address.city'], ['BT-38', 'seller.address.postcode'], ['BT-39', 'seller.address.subdivision'], ['BT-41', 'seller.contact.name'],
            ['BT-42', 'seller.contact.phone'], ['BT-43', 'seller.contact.email'], ['BT-45', 'buyer.tradingName'], ['BT-47', 'buyer.legalRegistration.value'],
            ['BT-50', 'buyer.address.line1'], ['BT-51', 'buyer.address.line2'], ['BT-163', 'buyer.address.line3'], ['BT-52', 'buyer.address.city'],
            ['BT-53', 'buyer.address.postcode'], ['BT-54', 'buyer.address.subdivision'], ['BT-56', 'buyer.contact.name'], ['BT-57', 'buyer.contact.phone'],
            ['BT-58', 'buyer.contact.email'], ['BT-60', 'payee.identifier.value'], ['BT-61', 'payee.legalRegistration.value'],
            ['BT-64', 'sellerTaxRepresentative.address.line1'], ['BT-65', 'sellerTaxRepresentative.address.line2'], ['BT-164', 'sellerTaxRepresentative.address.line3'],
            ['BT-66', 'sellerTaxRepresentative.address.city'], ['BT-67', 'sellerTaxRepresentative.address.postcode'], ['BT-68', 'sellerTaxRepresentative.address.subdivision'],
            ['BT-70', 'delivery.recipientName'], ['BT-71', 'delivery.locationId.value'], ['BT-75', 'delivery.address.line1'], ['BT-76', 'delivery.address.line2'],
            ['BT-165', 'delivery.address.line3'], ['BT-77', 'delivery.address.city'], ['BT-78', 'delivery.address.postcode'], ['BT-79', 'delivery.address.subdivision'],
            ['BT-22', 'notes[0].content'], ['BT-29', 'seller.identifiers[0].value'], ['BT-97', 'allowances[0].reason'], ['BT-104', 'charges[0].reason'],
            ['BT-123', 'attachments[0].description'], ['BT-127', 'line 2.note'], ['BT-128', 'line 2.objectId.value'], ['BT-132', 'line 2.orderLineReference'],
            ['BT-133', 'line 2.buyerAccountingReference'], ['BT-139', 'line 2.allowances[0].reason'], ['BT-144', 'line 2.charges[0].reason'],
            ['BT-154', 'line 2.item.description'], ['BT-155', 'line 2.item.sellerId'], ['BT-156', 'line 2.item.buyerId'], ['BT-157', 'line 2.item.standardId.value'],
            ['BT-158', 'line 2.item.classifications[0].code'],
        ],
    ],
    'buyer reference, electronic addresses, credit transfers and external attachment' => [
        fn () => peppolBuilder()->buyerReference(' ')->purchaseOrderReference('PO-2')
            ->seller(fn ($s) => $s->electronicAddress(' ', '9930'))
            ->buyer(fn ($b) => $b->electronicAddress(' ', '0208'))
            ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'DE02120300000000202051', accountName: ' ', remittanceInformation: ' ')
            ->creditTransfer('DE89370400440532013000', ' ', ' ')
            ->attachment('A-1', null, null, null, 'Timesheet', ' '),
        [
            ['BT-10', 'buyerReference'], ['BT-34', 'seller.electronicAddress.value'], ['BT-49', 'buyer.electronicAddress.value'],
            ['BT-83', 'paymentInstructions.remittanceInformation'], ['BT-85', 'paymentInstructions.creditTransfers[0].accountName'],
            ['BT-85', 'paymentInstructions.creditTransfers[1].accountName'], ['BT-86', 'paymentInstructions.creditTransfers[1].serviceProviderId'],
            ['BT-124', 'attachments[0].externalUri'],
        ],
    ],
    'payment card' => [
        fn () => peppolBuilder()->payment(PaymentMeansCode::BankCard)->paymentCard(' ', ' '),
        [['BT-87', 'paymentInstructions.card.primaryAccountNumber'], ['BT-88', 'paymentInstructions.card.holderName']],
    ],
    'direct debit' => [
        fn () => peppolBuilder()->payment(PaymentMeansCode::SepaDirectDebit)->directDebit(' ', ' '),
        [['BT-89', 'paymentInstructions.directDebit.mandateReference'], ['BT-90', 'paymentInstructions.directDebit.creditorId']],
    ],
]);

// Values computed independently from the published algorithms, with digits in every position; an
// invalid value changes the check digit.
it('checks the check digits of the identifier schemes', function (string $scheme, string $code, array $valid, array $invalid) {
    $codes = fn (string $value) => Validate::codes(Validate::data(peppolBuilder()->seller(fn ($s) => $s->identifier($value, $scheme))->build(), 'peppol'));

    foreach ($valid as $value) {
        expect($codes($value))->not->toContain($code);
    }
    foreach ($invalid as $value) {
        expect($codes($value))->toContain($code);
    }
})->with([
    'GLN' => ['0088', 'PEPPOL-COMMON-R040', ['6371292619411', '2772429712410', '7141935739252'], ['6371292619412', '2772429712419', '7141935739253']],
    'Norwegian organisation number' => ['0192', 'PEPPOL-COMMON-R041', ['932462923', '148976880', '654342598'], ['932462924', '148976881', '654342597', '000000000']],
    'Belgian enterprise number' => ['0208', 'PEPPOL-COMMON-R043', ['0868522954', '0736387178', '0296668857'], ['0868522955', '0736387177']],
    'Swedish organisation number' => ['0007', 'PEPPOL-COMMON-R049', ['2258215850', '7618632819', '4534778230'], ['2258215851', '7618632818', '4534778239']],
    'Australian Business Number' => ['0151', 'PEPPOL-COMMON-R050', ['16044319829', '31023281556', '14281152896'], ['16044319828', '31023281557']],
    'partita IVA' => ['0211', 'PEPPOL-COMMON-R047', ['IT34377662480', 'IT92257843588', 'it74348296414'], ['IT34377662481', 'IT92257843587', 'IT1234567890']],
    'Danish ERSTORG number' => ['0198', 'PEPPOL-COMMON-R053', ['DK12345678'], ['XX12345678', 'DK1234567']],
]);

it('reports the format warnings of the Italian and Danish identifiers as warnings', function (InvoiceBuilder $builder, string $code) {
    $messages = array_filter(Validate::data($builder->build(), 'peppol')->messages, fn ($m) => $m->code === $code);

    expect(array_values(array_unique(array_map(fn ($m) => $m->severity, $messages), SORT_REGULAR)))->toBe([Severity::Warning]);
})->with([
    'R045' => [fn () => peppolBuilder()->seller(fn ($s) => $s->identifier('RSSMRA85T10A56', '0210')), 'PEPPOL-COMMON-R045'],
    'R046' => [fn () => peppolBuilder()->seller(fn ($s) => $s->electronicAddress('RSSMRA85T10A56', '9907')), 'PEPPOL-COMMON-R046'],
    'R047' => [fn () => peppolBuilder()->seller(fn ($s) => $s->identifier('IT00743110158', '0211')), 'PEPPOL-COMMON-R047'],
    'R053' => [fn () => peppolBuilder()->seller(fn ($s) => $s->identifier('12345678', '0198')), 'PEPPOL-COMMON-R053'],
]);

it('checks the Italian tax code of scheme 9907 in the seller electronic address but not in a legal registration', function () {
    $endpoint = peppolBuilder()->seller(fn ($s) => $s->electronicAddress('RSSMRA85T10A56', '9907'))->build();
    $registration = peppolBuilder()->buyer(fn ($b) => $b->legalRegistration('RSSMRA85T10A56', '9907'))->build();

    expect(Validate::codes(Validate::data($endpoint, 'peppol')))->toContain('PEPPOL-COMMON-R046')
        ->and(Validate::codes(Validate::data($registration, 'peppol')))->not->toContain('PEPPOL-COMMON-R046');
});

it('states the value of the business process that has the wrong format', function () {
    $messages = array_filter(Validate::data(peppolBuilder()->businessProcess('urn:fdc:peppol.eu:2017:poacc:billing:1:1.0')->build(), 'peppol')->messages, fn ($m) => $m->code === 'PEPPOL-EN16931-R007');

    expect(array_values(array_map(fn ($m) => [$m->bt, $m->path, $m->message], $messages)))->toBe([[
        'BT-23', 'businessProcess', 'The business process (BT-23) "urn:fdc:peppol.eu:2017:poacc:billing:1:1.0" must be in the format urn:fdc:peppol.eu:2017:poacc:billing:NN:1.0, where NN is the process number.',
    ]]);
});

it('recognises German organisations by their country code as the Schematron compares it', function () {
    $invoice = peppolParticipants(Scenarios::make('simple'))->type(InvoiceTypeCode::CorrectedInvoice)->precedingInvoice('RE-2026-0001', '2026-09-01')->build();
    $invoice = $invoice->with([
        'seller' => $invoice->seller->with(['address' => $invoice->seller->address->with(['countryCode' => ' de'])]),
        'buyer' => $invoice->buyer->with(['address' => $invoice->buyer->address->with(['countryCode' => "de\t"])]),
    ]);

    expect(Validate::codes(Validate::data($invoice, 'peppol', calculate: false)))->not->toContain('PEPPOL-EN16931-P0112');
});

it('checks an invoice without payment instructions and delivery', function () {
    $invoice = peppolBuilder()->build();
    $invoice = $invoice->with(['paymentInstructions' => null, 'delivery' => null]);
    $directDebit = peppolBuilder()->payment(PaymentMeansCode::SepaDirectDebit)->build();

    expect(array_filter(Validate::codes(Validate::data($invoice, 'peppol')), fn (string $code) => str_starts_with($code, 'PEPPOL')))->toBe([])
        ->and(Validate::codes(Validate::data($directDebit, 'peppol')))->toContain('PEPPOL-EN16931-R061');
});

it('reports no operational sign of a VAT total without accounting currency', function () {
    $invoice = peppolParticipants(Scenarios::make('simple'))->build();

    expect((string) $invoice->totals?->vatTotal?->getAmount())->not->toBe('0.00')
        ->and(Validate::codes(Validate::data($invoice, 'peppol')))->not->toContain('PEPPOL-EN16931-R055');
});

it('checks every allowance and charge amount against its base amount', function () {
    $allowance = fn (string $amount, ?string $percent, ?string $base) => [VatCategory::ReverseCharge, '0', $amount, $percent, 'Agreed', '95', $base, 'Reverse charge', 'VATEX-EU-AE'];
    // The amount is rounded to two decimals: 1 % of 1000.55 is 10.01.
    $rounded = peppolBuilder()->allowance(...$allowance('10.01', '1', '1000.55'))->build();
    $second = peppolBuilder()->allowance(...$allowance('5.00', null, null))->allowance(...$allowance('11.00', '1', '1000.00'))->build();

    expect(Validate::codes(Validate::data($rounded, 'peppol')))->not->toContain('PEPPOL-EN16931-R040')
        ->and(array_values(array_map(fn ($m) => $m->path, array_filter(Validate::data($second, 'peppol')->messages, fn ($m) => $m->code === 'PEPPOL-EN16931-R040'))))->toBe(['allowances[1].amount']);
});

it('checks the net amount of every line after a line without one', function () {
    $invoice = peppolLine(peppolBuilder(), fn ($l) => $l->netPrice('10.00'))->build();
    $invoice = $invoice->with(['lines' => [$invoice->lines[0]->with(['netAmount' => null]), $invoice->lines[1]->with(['netAmount' => Money::of('10.05', 'EUR')])]]);
    $messages = array_filter(Validate::data($invoice, 'peppol', calculate: false)->messages, fn ($m) => $m->code === 'PEPPOL-EN16931-R120');

    expect(array_values(array_map(fn ($m) => $m->path, $messages)))->toBe(["line {$invoice->lines[1]->id}.netAmount"]);
});

it('reports no empty text as whitespace', function () {
    $invoice = peppolBuilder()->build()->with(['contractReference' => '']);

    expect(Validate::codes(Validate::data($invoice, 'peppol')))->not->toContain('PEPPOL-EN16931-R008');
});

it('reports the business term and the path of each finding', function (Closure $builder, string $code, array $expected) {
    $messages = array_filter(Validate::data($builder()->build(), 'peppol')->messages, fn ($m) => $m->code === $code);

    expect(array_values(array_map(fn ($m) => [$m->severity, $m->bt, $m->path], $messages)))->toBe($expected);
})->with([
    'R005' => [fn () => peppolBuilder()->taxCurrency('EUR'), 'PEPPOL-EN16931-R005', [[Severity::Warning, 'BT-6', 'taxCurrency']]],
    'CL008' => [
        fn () => peppolBuilder()->seller(fn ($s) => $s->electronicAddress('rechnung@muster.de', 'EM'))->buyer(fn ($b) => $b->electronicAddress('ap@klant.be', 'EM')),
        'PEPPOL-EN16931-CL008',
        [[Severity::Error, 'BT-34-1', 'seller.electronicAddress.scheme'], [Severity::Error, 'BT-49-1', 'buyer.electronicAddress.scheme']],
    ],
    'R040 allowance and charge' => [
        fn () => peppolBuilder()
            ->allowance(VatCategory::ReverseCharge, '0', amount: '11.00', percent: '1', reason: 'Discount', reasonCode: '95', baseAmount: '1000.00', exemptionReason: 'Reverse charge', exemptionReasonCode: 'VATEX-EU-AE')
            ->charge(VatCategory::ReverseCharge, '0', amount: '21.00', percent: '2', reason: 'Freight', reasonCode: 'FC', baseAmount: '1000.00', exemptionReason: 'Reverse charge', exemptionReasonCode: 'VATEX-EU-AE'),
        'PEPPOL-EN16931-R040',
        [[Severity::Error, 'BT-92', 'allowances[0].amount'], [Severity::Error, 'BT-99', 'charges[0].amount']],
    ],
    'R041 allowance and charge' => [
        fn () => peppolBuilder()
            ->allowance(VatCategory::ReverseCharge, '0', amount: '10.00', percent: '1', reason: 'Discount', reasonCode: '95', exemptionReason: 'Reverse charge', exemptionReasonCode: 'VATEX-EU-AE')
            ->charge(VatCategory::ReverseCharge, '0', amount: '10.00', percent: '1', reason: 'Freight', reasonCode: 'FC', exemptionReason: 'Reverse charge', exemptionReasonCode: 'VATEX-EU-AE'),
        'PEPPOL-EN16931-R041',
        [[Severity::Error, 'BT-93', 'allowances[0].baseAmount'], [Severity::Error, 'BT-100', 'charges[0].baseAmount']],
    ],
    'R042 allowance and charge' => [
        fn () => peppolBuilder()
            ->allowance(VatCategory::ReverseCharge, '0', amount: '10.00', reason: 'Discount', reasonCode: '95', baseAmount: '1000.00', exemptionReason: 'Reverse charge', exemptionReasonCode: 'VATEX-EU-AE')
            ->charge(VatCategory::ReverseCharge, '0', amount: '10.00', reason: 'Freight', reasonCode: 'FC', baseAmount: '1000.00', exemptionReason: 'Reverse charge', exemptionReasonCode: 'VATEX-EU-AE'),
        'PEPPOL-EN16931-R042',
        [[Severity::Error, 'BT-94', 'allowances[0].percent'], [Severity::Error, 'BT-101', 'charges[0].percent']],
    ],
    'R061' => [fn () => peppolBuilder()->payment(PaymentMeansCode::SepaDirectDebit)->directDebit(null, 'DE98ZZZ09999999999'), 'PEPPOL-EN16931-R061', [[Severity::Error, 'BT-89', 'paymentInstructions.directDebit.mandateReference']]],
    'R121' => [fn () => peppolLine(peppolBuilder(), fn ($l) => $l->netPrice('10.00', -1)), 'PEPPOL-EN16931-R121', [[Severity::Error, 'BT-149', 'line 2.price.baseQuantity']]],
    'R130' => [fn () => peppolLine(peppolBuilder(), fn ($l) => $l->netPrice('10.00', 1, UnitCode::Day)), 'PEPPOL-EN16931-R130', [[Severity::Error, 'BT-150', 'line 2.price.baseQuantityUnit']]],
    'CL007' => [fn () => peppolBuilder()->taxCurrency('XCG', '1.1'), 'PEPPOL-EN16931-CL007', [[Severity::Error, 'BT-6', 'taxCurrency']]],
    'P0112' => [fn () => peppolBuilder()->type(InvoiceTypeCode::PartialInvoice), 'PEPPOL-EN16931-P0112', [[Severity::Error, 'BT-3', 'typeCode']]],
    'R002' => [fn () => peppolBuilder()->note('First')->note('Second'), 'PEPPOL-EN16931-R002', [[Severity::Error, 'BG-1', 'notes']]],
    'R003' => [fn () => peppolBuilder()->buyerReference(null), 'PEPPOL-EN16931-R003', [[Severity::Error, 'BT-10', 'buyerReference']]],
]);

it('requires the electronic addresses of seller and buyer', function () {
    $invoice = peppolBuilder()->build();
    $invoice = $invoice->with([
        'seller' => $invoice->seller->with(['electronicAddress' => null]),
        'buyer' => $invoice->buyer->with(['electronicAddress' => null]),
    ]);
    $messages = array_filter(Validate::data($invoice, 'peppol')->messages, fn ($m) => in_array($m->code, ['PEPPOL-EN16931-R010', 'PEPPOL-EN16931-R020'], true));

    expect(array_values(array_map(fn ($m) => [$m->code, $m->bt, $m->path], $messages)))->toBe([
        ['PEPPOL-EN16931-R010', 'BT-49', 'buyer.electronicAddress'],
        ['PEPPOL-EN16931-R020', 'BT-34', 'seller.electronicAddress'],
    ])
        // Without an electronic address there is no scheme to check.
        ->and(Validate::codes(Validate::data($invoice, 'peppol')))->not->toContain('PEPPOL-EN16931-CL008')
        ->and(Validate::codes(Validate::data(peppolBuilder()->build(), 'peppol')))->not->toContain('PEPPOL-EN16931-R010', 'PEPPOL-EN16931-R020');
});

it('accepts a line net amount within 0.02 of the exact amount', function (string $netAmount, bool $reported) {
    $invoice = peppolBuilder()->build();
    $line = $invoice->lines[0];
    $invoice = $invoice->with(['lines' => [$line->with(['netAmount' => Money::of($netAmount, 'EUR')])]]);
    $messages = array_filter(Validate::data($invoice, 'peppol', calculate: false)->messages, fn ($m) => $m->code === 'PEPPOL-EN16931-R120');

    expect(array_values(array_map(fn ($m) => [$m->bt, $m->path], $messages)))->toBe($reported ? [['BT-131', "line {$line->id}.netAmount"]] : []);
})->with([
    'exact' => ['1000.00', false],
    '0.02 above' => ['1000.02', false],
    '0.02 below' => ['999.98', false],
    '0.03 above' => ['1000.03', true],
    '0.03 below' => ['999.97', true],
]);

it('checks the format of every date the UBL writer writes', function () {
    $date = Date::of(10000, 1, 1);
    $invoice = peppolBuilder()->issueDate($date)->taxPointDate($date)->dueDate($date)->deliveryDate($date)->invoicingPeriod($date, $date)
        ->precedingInvoice('RE-2026-0001', $date)
        ->line(fn ($l) => $l->name('Support')->quantity(1, UnitCode::Hour)->netPrice('10.00')->period($date, $date)->vat(VatCategory::ReverseCharge, '0', 'Reverse charge', 'VATEX-EU-AE'))
        ->build();
    $creditNote = peppolBuilder()->type(InvoiceTypeCode::CreditNote)->precedingInvoice('RE-2026-0001', '2026-09-01')->dueDate($date)->build();
    $messages = array_filter(Validate::data($invoice, 'peppol')->messages, fn ($m) => $m->code === 'PEPPOL-EN16931-F001');

    expect(array_values(array_map(fn ($m) => [$m->bt, $m->path], $messages)))->toEqualCanonicalizing([
        ['BT-2', 'issueDate'], ['BT-7', 'taxPointDate'], ['BT-9', 'dueDate'], ['BT-72', 'delivery.actualDeliveryDate'],
        ['BT-73', 'invoicingPeriod.start'], ['BT-74', 'invoicingPeriod.end'], ['BT-26', 'precedingInvoices[0].issueDate'],
        ['BT-134', 'line 2.period.start'], ['BT-135', 'line 2.period.end'],
    ])->and(array_values($messages)[0]->message)->toContain('10000-01-01')
        // A credit note carries the due date as cbc:PaymentDueDate, which the rule does not check.
        ->and(Validate::codes(Validate::data($creditNote, 'peppol')))->not->toContain('PEPPOL-EN16931-F001');
});

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

    // PEPPOL-EN16931-CL001 (BR-CL-24, MimeType without Xml), CL002 (BR-CL-19), CL003 (BR-CL-20), CL006 (BR-CL-06).
    $mimeTypes = array_map(fn (MimeType $type) => $type->value, array_filter(MimeType::cases(), fn (MimeType $type) => $type !== MimeType::Xml));
    expect($list('MIMECODE'))->toBe($codes(CodeList::MIME_TYPES))
        ->and($mimeTypes)->toEqualCanonicalizing($codes(CodeList::MIME_TYPES))
        ->and($list('UNCL5189'))->toBe($codes(CodeList::ALLOWANCE_REASONS))
        ->and($list('UNCL7161'))->toBe($codes(CodeList::CHARGE_REASONS))
        ->and($list('UNCL2005'))->toBe(['3', '35', '432']);
})->group('official')->skip(fn () => ! is_file(Official::root().'/peppol/PEPPOL-EN16931-UBL.sch'), 'The Peppol Schematron is not available (run tools/schemas/download.sh).');

it('rejects XML attachments with BR-CL-24, as the official rules do with BR-CL-24 and PEPPOL-EN16931-CL001', function () {
    $invoice = (new En16931Calculator)->calculate(peppolParticipants(Scenarios::simple())
        ->attachment('ATT-1', '<data/>', MimeType::Xml, 'data.xml', 'Data')
        ->build());
    $xml = (new UblWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get('peppol'));

    $php = array_map(fn ($m) => $m->code, array_filter(Validate::data($invoice, 'peppol', calculate: false)->messages, fn ($m) => $m->isError()));
    $official = array_map(fn ($m) => $m->code, array_filter(Official::kosit()->validateXml($xml), fn ($m) => $m->isError()));

    expect($xml)->toContain('mimeCode="application/xml"')
        ->and(array_values($php))->toBe(['BR-CL-24'])
        ->and($official)->toContain('BR-CL-24', 'PEPPOL-EN16931-CL001');
})->group('official')->skip(fn () => ! Official::available() || ! is_file(Official::root().'/peppol/PEPPOL-EN16931-UBL.xsl'), 'KoSIT validator or Peppol artifacts are not available (run tools/schemas/download.sh).');

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

it('warns about an accounting currency only when it equals the invoice currency', function () {
    expect(Validate::codes(Validate::data(peppolBuilder()->taxCurrency('USD', '1.1')->build(), 'peppol')))->not->toContain('PEPPOL-EN16931-R005');
});
