<?php

declare(strict_types=1);

use Mpdf\Mpdf;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Pdf\EmbeddedXml;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Pdf\Renderers\CallbackRenderer;
use Quittio\Core\Pdf\Renderers\MpdfRenderer;
use Quittio\Core\Pdf\Renderers\PassthroughRenderer;
use Quittio\Core\Pdf\RenderRequest;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Xml\Cii\CiiWriter;

function zugferdXml(string $profile = 'en16931'): string
{
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->build());

    return (new CiiWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get($profile));
}

function zugferdPdf(string $xml, string $profile = 'en16931'): string
{
    $p = ProfileRegistry::withDefaults()->get($profile);

    return (new MpdfRenderer)->render(new RenderRequest(
        html: '<h1>Invoice INV-2026-0001</h1><p>Consulting</p>',
        title: 'Invoice INV-2026-0001',
        author: 'Seller Ltd',
        embedded: new EmbeddedXml($xml, (string) $p->embeddedFilename(), (string) $p->afRelationship(), $p->xmpConformanceLevel()),
    ));
}

function plainPdf(): string
{
    $mpdf = new Mpdf(['tempDir' => sys_get_temp_dir().'/quittio-mpdf']);
    $mpdf->WriteHTML('<p>Foreign document</p>');

    return (string) $mpdf->Output('', 'S');
}

it('embeds the XML as associated file with PDF/A-3 and Factur-X metadata', function () {
    $pdf = zugferdPdf(zugferdXml());

    expect($pdf)->toStartWith('%PDF-')
        ->and($pdf)->toContain('/AF ')
        ->and($pdf)->toContain('/AFRelationship /Alternative')
        ->and($pdf)->toContain('(factur-x.xml)')
        ->and($pdf)->toContain('/Subtype /text#2Fxml')
        ->and($pdf)->toContain('<pdfaid:part>3</pdfaid:part>')
        ->and($pdf)->toContain('<pdfaid:conformance>B</pdfaid:conformance>')
        ->and($pdf)->toContain('<fx:DocumentFileName>factur-x.xml</fx:DocumentFileName>')
        ->and($pdf)->toContain('<fx:ConformanceLevel>EN 16931</fx:ConformanceLevel>')
        ->and($pdf)->toContain('/OutputIntents');
});

it('embeds the XML byte for byte as written', function (string $profile) {
    $xml = zugferdXml($profile);
    $extracted = (new PdfEmbeddedFileExtractor)->extract(zugferdPdf($xml, $profile));

    expect($extracted)->not->toBeNull()
        ->and($extracted[0])->toBe('factur-x.xml')
        ->and($extracted[1])->toBe($xml);
})->with(['en16931', 'basic', 'extended']);

it('writes the conformance level of the profile into the XMP metadata', function () {
    expect(zugferdPdf(zugferdXml('extended'), 'extended'))->toContain('<fx:ConformanceLevel>EXTENDED</fx:ConformanceLevel>');
});

it('reads the invoice back from the generated PDF', function () {
    $result = (new InvoiceReader)->read(zugferdPdf(zugferdXml()));

    expect($result->data->number)->toBe('INV-2026-0001')
        ->and($result->embeddedFilename)->toBe('factur-x.xml')
        ->and($result->profile)->toBe('en16931')
        ->and($result->findings)->toBe([]);
});

it('returns null for PDFs without an embedded invoice', function () {
    expect((new PdfEmbeddedFileExtractor)->extract(plainPdf()))->toBeNull();
});

it('stops decompressing at the size limit', function () {
    $xml = str_repeat('<!-- padding -->', 20_000);
    $pdf = zugferdPdf('<?xml version="1.0"?><x>'.$xml.'</x>');

    (new PdfEmbeddedFileExtractor(maxDecompressedBytes: 10_000))->extract($pdf);
})->throws(PdfException::class);

it('rejects input that is not a PDF', function () {
    (new PdfEmbeddedFileExtractor)->extract('not a pdf');
})->throws(PdfException::class);

it('adds PDF/A and the XML to a foreign PDF', function () {
    $xml = zugferdXml();
    $pdf = (new PassthroughRenderer(plainPdf()))->render(new RenderRequest('', 'Invoice', embedded: new EmbeddedXml($xml)));

    expect($pdf)->toContain('<pdfaid:part>3</pdfaid:part>')
        ->and((new PdfEmbeddedFileExtractor)->extract($pdf)[1] ?? null)->toBe($xml);
});

it('refuses passthrough content that is not a PDF', function () {
    new PassthroughRenderer('<html></html>');
})->throws(PdfException::class);

it('post processes the output of a render callback', function () {
    $seen = null;
    $renderer = new CallbackRenderer(function (string $html) use (&$seen): string {
        $seen = $html;

        return plainPdf();
    });
    $xml = zugferdXml();
    $pdf = $renderer->render(new RenderRequest('<p>Hello</p>', 'Invoice', embedded: new EmbeddedXml($xml)));

    expect($seen)->toBe('<p>Hello</p>')
        ->and((new PdfEmbeddedFileExtractor)->extract($pdf)[1] ?? null)->toBe($xml);
});

it('fails when a render callback returns no PDF', function () {
    (new CallbackRenderer(fn (): string => 'oops'))->render(new RenderRequest('<p></p>', 'Invoice'));
})->throws(PdfException::class);

it('takes font files from the configured directories before the bundled ones', function () {
    $fonts = sys_get_temp_dir().'/quittio-fonts-'.bin2hex(random_bytes(4));
    mkdir($fonts);
    // A file named like the bundled Inter, with another font inside.
    copy(dirname(__DIR__, 5).'/vendor/mpdf/mpdf/ttfonts/DejaVuSans.ttf', $fonts.'/Inter-Regular.ttf');
    $render = static fn (array $directories): string => (new MpdfRenderer(tempDir: sys_get_temp_dir().'/quittio-mpdf-'.bin2hex(random_bytes(4)), fontDirectories: $directories))
        ->render(new RenderRequest(html: '<p>Font</p>', title: 'Font', pdfa: false));

    expect($render([$fonts]))->toMatch('#/BaseFont\s*/[A-Z]{6}\+DejaVuSans#')
        ->and($render([]))->toMatch('#/BaseFont\s*/[A-Z]{6}\+Inter#');
});
