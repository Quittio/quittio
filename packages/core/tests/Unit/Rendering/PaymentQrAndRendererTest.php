<?php

declare(strict_types=1);

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Pdf\PdfRenderer;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Pdf\Renderers\MpdfRenderer;
use Quittio\Core\Pdf\RenderRequest;
use Quittio\Core\Quittio;
use Quittio\Core\Tests\Support\Invoices;

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
