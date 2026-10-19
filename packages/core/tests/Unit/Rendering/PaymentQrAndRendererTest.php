<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Pdf\PdfRenderer;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Pdf\Qr\PaymentSlip;
use Quittio\Core\Pdf\Qr\ProvidesPaymentSlip;
use Quittio\Core\Pdf\Qr\QrImage;
use Quittio\Core\Pdf\Renderers\MpdfRenderer;
use Quittio\Core\Pdf\RenderRequest;
use Quittio\Core\Quittio;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;

final class FixedQrGenerator implements PaymentQrGenerator
{
    public function key(): string
    {
        return 'swiss-qr-bill';
    }

    public function supports(InvoiceData $invoice): bool
    {
        return true;
    }

    public function payload(InvoiceData $invoice, array $options = []): ?string
    {
        return "SPC\r\n0200\r\n1";
    }
}

final class SlipQrGenerator implements ProvidesPaymentSlip
{
    public function key(): string
    {
        return 'swiss-qr-bill';
    }

    public function supports(InvoiceData $invoice): bool
    {
        return true;
    }

    public function payload(InvoiceData $invoice, array $options = []): ?string
    {
        return "SPC\n0200\n1";
    }

    public function slip(InvoiceData $invoice, string $payload, string $locale): PaymentSlip
    {
        return new PaymentSlip(
            title: 'Payment part '.$locale,
            receiptTitle: 'Receipt',
            sections: [['heading' => 'Account / Payable to', 'lines' => ['CH44 3199 9123 0008 8901 2', 'Seller Ltd']], ['heading' => 'Payable by (name/address)', 'lines' => []]],
            receiptSections: [['heading' => 'Account / Payable to', 'lines' => ['CH44 3199 9123 0008 8901 2']]],
            currencyHeading: 'Currency',
            amountHeading: 'Amount',
            currency: $invoice->currency,
            amount: null,
            acceptancePoint: 'Acceptance point',
            separationNote: 'Separate before paying in',
        );
    }
}

/**
 * Renders a plain PDF: neither PDF/A nor embedded files.
 */
final class PlainPdfRenderer implements PdfRenderer
{
    public function render(RenderRequest $request): string
    {
        return (new MpdfRenderer)->render(new RenderRequest($request->html, $request->title, $request->author, paper: $request->paper, pdfa: false));
    }

    public function supportsEmbedding(): bool
    {
        return false;
    }
}

function swissInvoice(): InvoiceData
{
    return Invoices::simple()->build()->with(['presentation' => new Presentation(paymentQr: Presentation::PAYMENT_QR_SWISS)]);
}

it('refuses the Swiss QR bill without a generator instead of leaving the code out', function () {
    Quittio::create()->make(swissInvoice())->format('html')->render();
})->throws(ConfigurationException::class, 'needs a generator');

it('uses a registered payment QR generator', function () {
    $html = Quittio::create()->setPaymentQrGenerator(new FixedQrGenerator)->make(swissInvoice())->format('html')->render()->html();

    expect($html)->toContain('data:image/png;base64');
});

it('prints the code of a generator with a payment slip on the slip', function () {
    $quittio = Quittio::create()->setPaymentQrGenerator(new SlipQrGenerator);
    $html = $quittio->make(swissInvoice())->format('html')->render()->html();

    expect($html)->toContain('<pagebreak page-selector="slip" />')
        ->and($html)->toContain('Payment part en')
        ->and($html)->toContain('Separate before paying in')
        ->and($html)->toContain('Payable by (name/address)')
        ->and(substr_count($html, 'class="slip-field"'))->toBe(3)
        ->and($html)->not->toContain('class="qr"');
});

it('puts the slip into the view model, and nothing for codes without one', function () {
    $model = fn (PaymentQrGenerator $generator) => (new ViewModelFactory)->build((new En16931Calculator)->calculate(swissInvoice()), new ViewContext(paymentQr: $generator));

    expect($model(new SlipQrGenerator)->payment?->qrCode?->slip?->sections[0]?->lines[1])->toBe('Seller Ltd')
        ->and($model(new SlipQrGenerator)->payment?->qrCode?->slip?->dataUri)->toStartWith('data:image/')
        ->and($model(new FixedQrGenerator)->payment?->qrCode?->slip)->toBeNull();
});

it('renders a code without quiet zone', function () {
    $with = imagecreatefromstring(QrImage::png('SPC', 100));
    $without = imagecreatefromstring(QrImage::png('SPC', 100, 'M', border: false));

    // The quiet zone is white; without it the code starts with a dark module in the corner.
    expect(imagecolorat($with, 0, 0) & 0xFFFFFF)->toBe(0xFFFFFF)
        ->and(imagecolorat($without, 0, 0) & 0xFFFFFF)->toBe(0x000000);
})->skip(! extension_loaded('gd'), 'GD is not available.');

it('adds PDF/A and the XML for renderers that cannot embed', function () {
    $pdf = Quittio::create(renderer: new PlainPdfRenderer)->make(Invoices::simple()->build())->format('zugferd')->render()->pdf();

    expect((new PdfEmbeddedFileExtractor)->extract((string) $pdf))->not->toBeNull()
        ->and((string) $pdf)->toContain('pdfaid:part');
});

it('post-processes in the configured temporary directory', function () {
    $dir = sys_get_temp_dir().'/quittio-test-'.bin2hex(random_bytes(4));

    try {
        Quittio::create(['temp_dir' => $dir], renderer: new PlainPdfRenderer)->make(Invoices::simple()->build())->format('zugferd')->render();

        expect(glob($dir.'/*'))->not->toBe([]);
    } finally {
        if (! is_dir($dir)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
});
