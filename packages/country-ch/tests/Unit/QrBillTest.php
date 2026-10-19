<?php

declare(strict_types=1);

use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Quittio;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;
use Quittio\Country\CH\CountryCH;
use Quittio\Country\CH\QrBill\CreditorReference;
use Quittio\Country\CH\QrBill\QrReference;
use Quittio\Country\CH\QrBill\SwissCross;
use Quittio\Country\CH\QrBill\SwissQrBillGenerator;
use Quittio\Country\CH\Tests\Support\Swiss;

it('computes the check digit of a QR reference by modulo 10 recursive', function () {
    // The example of annex B of the guidelines.
    expect(QrReference::checkDigit('21000000000313947143000901'))->toBe('7')
        ->and(QrReference::isValid('21 00000 00003 13947 14300 09017'))->toBeTrue()
        ->and(QrReference::isValid('210000000003139471430009018'))->toBeFalse()
        ->and(QrReference::fromDigitsOf('RE-2026-0042'))->toBe('000000000000000000202600427')
        ->and(QrReference::format('210000000003139471430009017'))->toBe('21 00000 00003 13947 14300 09017')
        ->and(QrReference::fromDigitsOf('ABC'))->toBeNull();
});

it('creates and checks Creditor References of ISO 11649', function () {
    expect(CreditorReference::isValid('RF18 5390 0754 7034'))->toBeTrue()
        ->and(CreditorReference::isValid('RF19 5390 0754 7034'))->toBeFalse()
        ->and(CreditorReference::fromCharactersOf('539007547034'))->toBe('RF18539007547034')
        ->and(CreditorReference::isValid((string) CreditorReference::fromCharactersOf('RE-2026-0042')))->toBeTrue()
        ->and(CreditorReference::format('RF18539007547034'))->toBe('RF18 5390 0754 7034');
});

it('recognises a QR-IBAN by its institution identification', function () {
    expect(SwissQrBillGenerator::isQrIban(Swiss::QR_IBAN))->toBeTrue()
        ->and(SwissQrBillGenerator::isQrIban(Swiss::IBAN))->toBeFalse();
});

it('writes the Swiss QR Code with a QR reference from the invoice number', function () {
    $payload = (new SwissQrBillGenerator)->payload(Swiss::calculated(Swiss::invoice()));

    expect($payload)->toBe(implode("\n", [
        'SPC', '0200', '1', 'CH4431999123000889012',
        'S', 'Bergsoft AG', 'Bahnhofstrasse', '12', '8001', 'Zürich', 'CH',
        '', '', '', '', '', '', '',
        '1960.97', 'CHF',
        'S', 'Muster GmbH', 'Marktgasse', '28', '3011', 'Bern', 'CH',
        'QRR', '000000000000000000202600427', 'RE-2026-0042', 'EPD',
    ]));
});

it('uses a Creditor Reference or no reference with an ordinary IBAN', function () {
    $scor = (new SwissQrBillGenerator)->payload(Swiss::calculated(Swiss::invoice(Swiss::IBAN)->payment(PaymentMeansCode::SepaCreditTransfer, iban: Swiss::IBAN, remittanceInformation: 'RF18 5390 0754 7034')));
    $non = (new SwissQrBillGenerator)->payload(Swiss::calculated(Swiss::invoice(Swiss::IBAN)));

    expect(array_slice(explode("\n", (string) $scor), 27, 3))->toBe(['SCOR', 'RF18539007547034', 'RE-2026-0042'])
        ->and(array_slice(explode("\n", (string) $non), 27, 3))->toBe(['NON', '', 'RE-2026-0042']);
});

it('creates no code without an account of Switzerland or Liechtenstein, in other currencies or without amount', function () {
    $generator = new SwissQrBillGenerator;

    expect($generator->supports(Swiss::calculated(Swiss::invoice('DE02120300000000202051'))))->toBeFalse()
        ->and($generator->supports(Swiss::calculated(Swiss::invoice()->currency('USD'))))->toBeFalse()
        ->and($generator->supports(Swiss::calculated(Swiss::invoice())))->toBeTrue()
        ->and($generator->payload(Swiss::calculated(Swiss::invoice()->prepaid('1960.97'))))->toBeNull();
});

it('prints the payment part and receipt in the language of the invoice', function (string $locale, string $title, string $account) {
    $generator = new SwissQrBillGenerator;
    $invoice = Swiss::calculated(Swiss::invoice());
    $slip = $generator->slip($invoice, (string) $generator->payload($invoice), $locale);

    expect($slip?->title)->toBe($title)
        ->and($slip?->sections[0])->toBe(['heading' => $account, 'lines' => ['CH44 3199 9123 0008 8901 2', 'Bergsoft AG', 'Bahnhofstrasse 12', '8001 Zürich']])
        ->and($slip?->sections[1]['lines'])->toBe(['00 00000 00000 00000 02026 00427'])
        ->and(array_column($slip->receiptSections ?? [], 'heading'))->not->toContain($slip?->sections[2]['heading'])
        ->and($slip?->amount)->toBe('1 960.97')
        ->and($slip?->mark)->toBe(SwissCross::dataUri());
})->with([
    ['de_CH', 'Zahlteil', 'Konto / Zahlbar an'],
    ['fr', 'Section paiement', 'Compte / Payable à'],
    ['it', 'Sezione pagamento', 'Conto / Pagabile a'],
    ['en', 'Payment part', 'Account / Payable to'],
]);

it('leaves a field for the debtor when the buyer has no complete address', function () {
    $generator = new SwissQrBillGenerator;
    $invoice = Swiss::calculated(Swiss::invoice()->buyer(fn ($b) => $b->name('Barverkauf')->address(null, null, null, 'CH')));
    $slip = $generator->slip($invoice, (string) $generator->payload($invoice), 'de');

    expect(explode("\n", (string) $generator->payload($invoice))[20])->toBe('')
        ->and($slip?->sections[array_key_last($slip->sections)])->toBe(['heading' => 'Zahlbar durch (Name/Adresse)', 'lines' => []]);
});

it('puts the slip into the view model of a Swiss invoice', function () {
    $quittio = Quittio::create([], [new CountryCH]);
    $invoice = Swiss::calculated(Swiss::invoice());
    $model = (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de_CH', country: new CountryCH));

    expect($model->payment?->qrCode?->type)->toBe('swiss-qr-bill')
        ->and($model->payment?->qrCode?->slip?->title)->toBe('Zahlteil')
        ->and($model->payment?->qrCode?->slip?->dataUri)->toStartWith('data:image/png;base64,')
        ->and($quittio->countries()->get('CH'))->toBeInstanceOf(CountryCH::class);
});

it('renders the payment part on a page of its own', function () {
    $result = Quittio::create([], [new CountryCH])->make(Swiss::calculated(Swiss::invoice()))->format('pdf')->render();
    expect($result->pdf())->toStartWith('%PDF')
        ->and(substr_count($result->pdf(), '/Type /Page') - substr_count($result->pdf(), '/Type /Pages'))->toBe(2);
})->skip(fn () => ! function_exists('imagecreatetruecolor'), 'GD is not available.');

it('keeps PDF/A-3b with the payment part, according to veraPDF', function (string $format) {
    $quittio = Quittio::create(['validation' => ['verapdf' => ['enabled' => true]]], [new CountryCH]);
    $result = $quittio->make(Swiss::calculated(Swiss::invoice()))->format($format)->render();

    expect($result->validation()->levels)->toContain(ValidationLevel::L6)
        ->and($result->validation()->byLevel(ValidationLevel::L6))->toBe([]);
})->with(['zugferd', 'pdf'])->group('official')->skip(function () {
    exec('docker image inspect verapdf/cli 2>&1', $output, $code);

    return $code !== 0 && getenv('CI') !== 'true';
}, 'Docker image verapdf/cli is not available.');
