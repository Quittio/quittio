<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Countries\AbstractCountryPack;
use Quittio\Core\Countries\CorrectionPolicy;
use Quittio\Core\Countries\CountryDefaults;
use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Quittio;
use Quittio\Core\Reading\FormatReader;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Reading\UnreadableInvoiceException;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;

/**
 * A pack for the country "DE" with the given hooks; everything else is neutral.
 *
 * @param  list<FormatReader>  $readers
 */
function hookPack(?CountryDefaults $defaults = null, ?PaymentQrGenerator $qr = null, array $readers = [], ?CorrectionPolicy $policy = null): CountryPack
{
    return new class($defaults, $qr, $readers, $policy) extends AbstractCountryPack
    {
        /**
         * @param  list<FormatReader>  $readers
         */
        public function __construct(
            private readonly ?CountryDefaults $ownDefaults,
            private readonly ?PaymentQrGenerator $qr,
            private readonly array $ownReaders,
            private readonly ?CorrectionPolicy $policy,
        ) {}

        public function code(): string
        {
            return 'DE';
        }

        public function defaults(): CountryDefaults
        {
            return $this->ownDefaults ?? parent::defaults();
        }

        public function paymentQr(): ?PaymentQrGenerator
        {
            return $this->qr;
        }

        public function readers(): array
        {
            return array_map(static fn (FormatReader $reader): FormatReader => clone $reader, $this->ownReaders);
        }

        public function correctionPolicy(): CorrectionPolicy
        {
            return $this->policy ?? parent::correctionPolicy();
        }
    };
}

/**
 * Reads <national-invoice number="…"/> documents; with $all = true it claims every document.
 */
function nationalReader(bool $all = false): FormatReader
{
    return new class($all) implements FormatReader
    {
        public function __construct(private readonly bool $all) {}

        public function canRead(DOMDocument $document): bool
        {
            return $this->all || $document->documentElement?->localName === 'national-invoice';
        }

        public function read(DOMDocument $document, string $xml): ReadResult
        {
            $data = (new En16931Calculator)->calculate(Invoices::simple()->number($document->documentElement?->getAttribute('number') ?? '')->build());

            return new ReadResult($data, 'national', null, null, null, [], [], $xml);
        }
    };
}

function qrStub(string $key = 'national-qr'): PaymentQrGenerator
{
    return new class($key) implements PaymentQrGenerator
    {
        public function __construct(private readonly string $key) {}

        public function key(): string
        {
            return $this->key;
        }

        public function supports(InvoiceData $invoice): bool
        {
            return true;
        }

        public function payload(InvoiceData $invoice, array $options = []): string
        {
            return 'PAY '.$invoice->number;
        }
    };
}

function qrType(CountryPack $pack): ?string
{
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->build());
    $view = (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'en', country: $pack));

    return $view->payment?->qrCode?->type;
}

it('reads national formats with the readers of the registered country packs', function () {
    $quittio = Quittio::create([], [hookPack(readers: [nationalReader()])]);
    $read = $quittio->read('<national-invoice number="N-7"/>');
    // The XSD driver (L4) knows only CII and UBL; a pack adds validators for its formats.
    $withoutXsd = Quittio::create(['validation' => ['levels' => ['L1', 'L2', 'L3', 'L7']]], [hookPack(readers: [nationalReader()])]);

    expect($read->syntax)->toBe('national')
        ->and($read->data->number)->toBe('N-7')
        ->and($withoutXsd->validateFile('<national-invoice number="N-8"/>')->isValid())->toBeTrue()
        ->and(fn () => Quittio::create()->read('<national-invoice number="N-9"/>'))->toThrow(UnreadableInvoiceException::class);
});

it('reads CII and UBL with the core readers before the readers of country packs', function () {
    $xml = Quittio::create()->make(Invoices::simple())->format('cii')->render()->xml();

    expect(Quittio::create([], [hookPack(readers: [nationalReader(all: true)])])->read((string) $xml)->syntax)->toBe('cii');
});

it('prints the payment QR code that the country names for the type auto', function () {
    expect(qrType(hookPack()))->toBe('girocode')
        ->and(qrType(hookPack(qr: qrStub())))->toBe('national-qr')
        ->and(qrType(hookPack(new CountryDefaults(paymentQr: 'none'), qrStub())))->toBeNull()
        ->and(qrType(hookPack(new CountryDefaults(paymentQr: 'swiss-qr-bill'))))->toBeNull()
        ->and(qrType(hookPack(new CountryDefaults(paymentQr: 'swiss-qr-bill'), qrStub('swiss-qr-bill'))))->toBe('swiss-qr-bill');
});

it('titles self-billed invoices with the self-billed title of the correction policy', function () {
    $policy = new CorrectionPolicy(titles: ['self_billed' => ['de' => 'Gutschrift', 'en' => 'Self-billed invoice (Gutschrift)']]);
    $quittio = Quittio::create([], [hookPack(policy: $policy)]);
    $selfBilled = static fn () => Invoices::simple()->type(InvoiceTypeCode::SelfBilledInvoice);

    expect($quittio->make($selfBilled())->format('html')->locale('en')->render()->html())->toContain('<h1>Self-billed invoice (Gutschrift)</h1>')
        ->and($quittio->make($selfBilled())->format('html')->locale('de')->render()->html())->toContain('<h1>Gutschrift</h1>')
        ->and($quittio->make($selfBilled())->format('cii')->locale('en')->render()->filename())->toBe('Self-billed_invoice_Gutschrift_INV-2026-0001.xml')
        ->and($quittio->make($selfBilled()->presentation(new Presentation(title: 'Credit')))->format('html')->render()->html())->toContain('<h1>Credit</h1>')
        ->and($quittio->make(Invoices::simple())->format('html')->locale('en')->render()->html())->toContain('<h1>Invoice</h1>')
        // Without a title in the policy the label of the type code applies.
        ->and(Quittio::create([], [hookPack()])->make($selfBilled())->format('html')->locale('en')->render()->html())->toContain('<h1>Self-billed invoice</h1>');
});
