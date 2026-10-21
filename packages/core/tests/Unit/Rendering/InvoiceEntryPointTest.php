<?php

declare(strict_types=1);

use Quittio\Core\Builder\BoundInvoiceBuilder;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\Party;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Config;
use Quittio\Core\Data\Meta;
use Quittio\Core\Data\Party\SellerParty;
use Quittio\Core\Documents\BoundDocumentBuilder;
use Quittio\Core\Documents\DocumentType;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Exceptions\LogicException;
use Quittio\Core\Quittio;
use Quittio\Core\Tax\CountryDefaultVat;
use Quittio\Country\DE\CountryDE;

afterEach(fn () => Quittio::resetState());

function coreEntrySeller(): PartyBuilder
{
    return Party::make('Muster GmbH')
        ->vatId('DE123456789')
        ->address('Musterstraße 1', '10115', 'Berlin', 'DE')
        ->contact('Max Muster', '+49 30 1234567', 'rechnung@muster.de')
        ->electronicAddress('rechnung@muster.de');
}

/**
 * The German pack, the default VAT of the seller's country for lines without VAT (19 %, or
 * the small business preset) and, unless $config says otherwise, the seller with its account.
 *
 * @param  array<string, mixed>  $config
 */
function coreEntryQuittio(array $config = [], bool $seller = true): Quittio
{
    $quittio = Quittio::create([
        'due_days' => 14,
        ...($seller ? [
            'seller' => coreEntrySeller(),
            'bank_account' => ['iban' => 'DE02 1203 0000 0000 2020 51', 'bic' => 'BYLADEM1001', 'name' => 'Muster GmbH'],
        ] : []),
        ...$config,
    ], [new CountryDE]);
    InvoiceBuilder::useDefaultVat(new CountryDefaultVat($quittio->countries()));

    return $quittio;
}

function coreEntryBuyer(): PartyBuilder
{
    return Party::make('Kunde AG')->vatId('DE987654321')->address('Weg 1', '54321', 'Beispielstadt', 'DE');
}

function coreEntryInvoice(Quittio $quittio): BoundInvoiceBuilder
{
    return $quittio->invoice()
        ->number('RE-2026-0042')
        ->issueDate('2026-10-14')
        ->deliveryDate('2026-10-14')
        ->buyerReference('04011000-12345-03')
        ->buyer(Party::make('Stadt Beispielstadt')->address('Rathausplatz 1', '54321', 'Beispielstadt', 'DE')->electronicAddress('04011000-12345-03', '0204'))
        ->line('Consulting', netPrice: '120.00', quantity: 4);
}

it('starts an invoice with the configured seller, its bank account and its small business flag', function () {
    $quittio = coreEntryQuittio(['small_business' => true]);

    $invoice = $quittio->invoice()
        ->buyer(coreEntryBuyer())
        ->line('Consulting', netPrice: '80.00')
        ->build();
    $account = $invoice->paymentInstructions?->primaryCreditTransfer();

    expect($invoice->seller->name)->toBe('Muster GmbH')
        ->and($invoice->seller->vatId)->toBe('DE123456789')
        ->and($invoice->paymentInstructions?->meansCode)->toBe(PaymentMeansCode::SepaCreditTransfer)
        ->and([$account?->accountId, $account?->serviceProviderId, $account?->accountName])->toBe(['DE02120300000000202051', 'BYLADEM1001', 'Muster GmbH'])
        ->and($invoice->meta->hasFlag(Meta::FLAG_SMALL_BUSINESS))->toBeTrue()
        ->and($invoice->lines[0]->vat->presetKey)->toBe('de.small_business');
});

it('gives another seller nothing of the configured seller', function () {
    $quittio = coreEntryQuittio(['small_business' => true]);
    $lisa = Party::make('Lisa Klein')->vatId('DE111111111')->address('Gartenstraße 7', '12345', 'Musterstadt', 'DE');

    $invoice = $quittio->invoice()->seller($lisa)->buyer(coreEntryBuyer())->line('Logo', netPrice: '650.00')->build();
    $quote = $quittio->quote()->seller($lisa)->buyer(coreEntryBuyer())->line('Logo', netPrice: '650.00')->build();
    $same = $quittio->invoice()->seller(fn (PartyBuilder $s) => $s->contact('Erika Muster'))->buyer(coreEntryBuyer())->line('Logo', netPrice: '650.00')->build();

    expect($invoice->seller->name)->toBe('Lisa Klein')
        ->and($invoice->paymentInstructions)->toBeNull()
        ->and($invoice->meta->hasFlag(Meta::FLAG_SMALL_BUSINESS))->toBeFalse()
        ->and($quote->seller->name)->toBe('Lisa Klein')
        ->and($quote->paymentInstructions)->toBeNull()
        ->and($quote->meta->hasFlag(Meta::FLAG_SMALL_BUSINESS))->toBeFalse()
        ->and($same->seller->contact?->name)->toBe('Erika Muster')                     // the same seller keeps its defaults
        ->and($same->paymentInstructions?->primaryCreditTransfer()?->accountId)->toBe('DE02120300000000202051')
        ->and($same->meta->hasFlag(Meta::FLAG_SMALL_BUSINESS))->toBeTrue();
});

it('replaces the configured bank account with payment() and adds further accounts with creditTransfer()', function () {
    $quittio = coreEntryQuittio();

    $replaced = $quittio->invoice()->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'DE89370400440532013000')
        ->buyer(coreEntryBuyer())->line('Logo', netPrice: '650.00')->build();
    $added = $quittio->invoice()->creditTransfer('DE89370400440532013000')
        ->buyer(coreEntryBuyer())->line('Logo', netPrice: '650.00')->build();

    expect(array_map(fn ($t) => $t->accountId, $replaced->paymentInstructions->creditTransfers ?? []))->toBe(['DE89370400440532013000'])
        ->and(array_map(fn ($t) => $t->accountId, $added->paymentInstructions->creditTransfers ?? []))->toBe(['DE02120300000000202051', 'DE89370400440532013000']);
});

it('starts empty builders without a configured seller', function () {
    $quittio = coreEntryQuittio(seller: false);

    $invoice = $quittio->invoice()->seller(coreEntrySeller())->buyer(coreEntryBuyer())->deliveryDate('2026-10-14')
        ->line('Logo', netPrice: '650.00')->number('RE-1');
    $quote = $quittio->quote()->number('AN-1')->seller(coreEntrySeller())->buyer(coreEntryBuyer())
        ->line('Logo', netPrice: '650.00');

    expect($quittio->config()->seller)->toBeNull()
        ->and($quittio->invoice())->toBeInstanceOf(BoundInvoiceBuilder::class)
        ->and(fn () => $quittio->invoice()->buyer(coreEntryBuyer())->line('Logo', netPrice: '1.00', vat: VatCategory::Exempt)->build())->toThrow(InvalidInvoiceData::class, 'Missing seller (BG-4)')
        ->and($invoice->build()->paymentInstructions)->toBeNull()
        ->and($invoice->check()->isValid())->toBeTrue()
        ->and($quote->render()->data()->seller->name)->toBe('Muster GmbH');
});

it('renders and checks at the end of the chain, in one or several formats', function () {
    $quittio = coreEntryQuittio();

    $automatic = coreEntryInvoice($quittio)->render();
    $one = coreEntryInvoice($quittio)->render('xrechnung-ubl');
    $several = coreEntryInvoice($quittio)->render(['zugferd', 'xrechnung-cii']);

    expect($automatic->format())->toBe('xrechnung-cii')
        ->and($automatic->data()->seller->name)->toBe('Muster GmbH')
        ->and($automatic->xml())->toContain('DE02120300000000202051')
        ->and($one->format())->toBe('xrechnung-ubl')
        ->and($several->formats())->toBe(['zugferd', 'xrechnung-cii'])
        ->and($several->data()->totals?->amountDue->isEqualTo('571.20'))->toBeTrue()
        ->and(coreEntryInvoice($quittio)->check()->isValid())->toBeTrue()
        ->and(coreEntryInvoice($quittio)->check('zugferd')->isValid())->toBeTrue()
        ->and(coreEntryInvoice($quittio)->check(['zugferd', 'xrechnung-ubl'])->isValid())->toBeTrue()
        ->and(coreEntryInvoice($quittio)->number('')->check('xrechnung-cii')->has('BR-02'))->toBeTrue();
});

it('starts every document with the configured seller and renders it', function (Closure $start, DocumentType $type, string $filename) {
    $quittio = coreEntryQuittio();
    $builder = $start($quittio)->issueDate('2026-10-14')->buyer(coreEntryBuyer());

    $rendered = $builder->render();

    expect($builder)->toBeInstanceOf(BoundDocumentBuilder::class)
        ->and($rendered->data()->type)->toBe($type)
        ->and($rendered->data()->seller->name)->toBe('Muster GmbH')
        ->and($rendered->data()->paymentInstructions?->primaryCreditTransfer()?->accountId)->toBe('DE02120300000000202051')
        ->and($rendered->pdf())->toStartWith('%PDF-')
        ->and($rendered->filename())->toBe($filename);
})->with([
    'quote' => [fn (Quittio $q) => $q->quote()->number('AN-1')->line('Workshop', netPrice: '1200.00'), DocumentType::Quote, 'Angebot_AN-1.pdf'],
    'order confirmation' => [fn (Quittio $q) => $q->orderConfirmation()->number('AB-1')->line('Workshop', netPrice: '1200.00'), DocumentType::OrderConfirmation, 'Auftragsbestätigung_AB-1.pdf'],
    'delivery note' => [fn (Quittio $q) => $q->deliveryNote()->number('LS-1')->line(['name' => 'Router']), DocumentType::DeliveryNote, 'Lieferschein_LS-1.pdf'],
    'proforma' => [fn (Quittio $q) => $q->proforma()->number('PF-1')->line('Workshop', netPrice: '1200.00'), DocumentType::Proforma, 'Proforma-Rechnung_PF-1.pdf'],
    'payment reminder' => [fn (Quittio $q) => $q->paymentReminder(1)->number('M-1')->openItem('RE-1', '2026-08-03', '1190.00', dueDate: '2026-08-17'), DocumentType::PaymentReminder, '1._Mahnung_M-1.pdf'],
    'bonus statement' => [fn (Quittio $q) => $q->bonusStatement()->number('BO-1')->bonusPeriod('2026-01-01', '2026-06-30')->bonusAgreement('der Bonusvereinbarung vom 15.01.2026')->bonus('300.00', VatCategory::StandardRate, '19'), DocumentType::BonusStatement, 'Bonusabrechnung_BO-1.pdf'],
    'account statement' => [fn (Quittio $q) => $q->accountStatement()->number('KA-1')->statementPeriod('2026-09-01', '2026-09-30')->openItem('RE-1', '2026-09-02', '500.00'), DocumentType::AccountStatement, 'Kontoauszug_KA-1.pdf'],
]);

it('keeps the level of a payment reminder', function () {
    $reminder = coreEntryQuittio()->paymentReminder(2)->number('M-2')->buyer(coreEntryBuyer())->openItem('RE-1', '2026-08-03', '1190.00')->build();

    expect($reminder->dunning?->level)->toBe(2);
});

it('refuses to render builders that no Quittio instance started', function (Closure $render) {
    $render();
})->with([
    'invoice' => [fn () => BoundInvoiceBuilder::make()->render()],
    'document' => [fn () => BoundDocumentBuilder::quote()->render()],
])->throws(LogicException::class, 'not started with');

it('reads the seller and its defaults from the configuration', function () {
    $seller = coreEntrySeller()->buildSeller();
    $built = Config::fromArray(['seller' => coreEntrySeller(), 'bank_account' => ['iban' => 'DE02120300000000202051'], 'small_business' => true]);
    $given = new Config(seller: $seller);

    expect($built->seller)->toBeInstanceOf(SellerParty::class)
        ->and($built->seller?->name)->toBe('Muster GmbH')
        ->and($built->bankAccount)->toBe(['iban' => 'DE02120300000000202051', 'bic' => null, 'name' => null])
        ->and($built->smallBusiness)->toBeTrue()
        ->and($given->seller)->toBe($seller)
        ->and($given->bankAccount)->toBeNull()
        ->and($given->smallBusiness)->toBeFalse()
        ->and($given->with(['locale' => 'de'])->seller)->toBe($seller)
        ->and((new Config)->seller)->toBeNull();
});

it('rejects invalid seller configuration', function (array $config, string $message) {
    expect(fn () => Config::fromArray($config))->toThrow(ConfigurationException::class, $message);
})->with([
    'seller of another type' => [['seller' => 'Muster GmbH'], 'must be a PartyBuilder'],
    'seller without address' => [['seller' => Party::make('Muster GmbH')], 'seller postal address'],
    'bank account not an array' => [['seller' => coreEntrySeller(), 'bank_account' => 'DE02120300000000202051'], '"bank_account" must be an array'],
    'bank account without IBAN' => [['seller' => coreEntrySeller(), 'bank_account' => ['bic' => 'BYLADEM1001']], 'bank_account.iban'],
    'empty IBAN' => [['seller' => coreEntrySeller(), 'bank_account' => ['iban' => ' ']], 'bank_account.iban'],
    'BIC not a string' => [['seller' => coreEntrySeller(), 'bank_account' => ['iban' => 'DE02120300000000202051', 'bic' => 1]], '"bic" must be a string'],
    'small business not a boolean' => [['seller' => coreEntrySeller(), 'small_business' => 'yes'], '"small_business" must be a boolean'],
    'bank account without seller' => [['bank_account' => ['iban' => 'DE02120300000000202051']], 'without a seller'],
    'small business without seller' => [['small_business' => true], 'without a seller'],
]);

it('rejects an incomplete seller and an empty IBAN in the constructor', function (Closure $config) {
    $config();
})->with([
    'seller without address' => [fn () => new Config(seller: Party::make('Muster GmbH'))],
    'empty IBAN' => [fn () => new Config(seller: coreEntrySeller(), bankAccount: ['iban' => ''])],
])->throws(ConfigurationException::class);
