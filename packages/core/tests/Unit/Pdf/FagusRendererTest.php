<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Fagus\Engine;
use Fagus\Fagus;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Pdf\EmbeddedXml;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Pdf\Renderers\FagusRenderer;
use Quittio\Core\Pdf\Renderers\MpdfRenderer;
use Quittio\Core\Pdf\RenderRequest;
use Quittio\Core\Quittio;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Country\DE\CountryDE;

/*
| The optional Fagus renderer with the default template (plain PHP). Skipped when the package
| nforst/fagus or its binary is not installed; text and fonts are read with poppler.
*/

function fagusAvailable(): bool
{
    static $available = null;
    if ($available === null) {
        try {
            $available = class_exists(Fagus::class) && (new Engine)->version() !== '';
        } catch (Throwable) {
            $available = false;
        }
    }

    return $available;
}

function fagusPoppler(): bool
{
    exec('command -v pdftotext && command -v pdffonts && command -v pdfimages', $output, $code);

    return $code === 0;
}

/**
 * Output of a poppler tool for the PDF.
 *
 * @return list<string>
 */
function fagusPopplerOutput(string $tool, string $pdf): array
{
    $file = tempnam(sys_get_temp_dir(), 'quittio-fagus-');
    file_put_contents((string) $file, $pdf);
    exec(sprintf('%s %s %s', $tool, escapeshellarg((string) $file), str_starts_with($tool, 'pdftotext') ? '-' : ''), $lines);
    unlink((string) $file);

    return $lines;
}

/**
 * Text of each page.
 *
 * @return list<string>
 */
function fagusPages(string $pdf): array
{
    return explode("\f", rtrim(implode("\n", fagusPopplerOutput('pdftotext -layout', $pdf)), "\f"));
}

function fagusQuittio(): Quittio
{
    return Quittio::create([], [new CountryDE])->setRenderer(new FagusRenderer);
}

it('renders the default template as PDF/A-3b with the title and author of the request', function () {
    $pdf = fagusQuittio()->make(Scenarios::make('simple')->build())->format('pdf')->validate(false)->render()->pdf();

    expect($pdf)->toStartWith('%PDF-')
        ->and($pdf)->toContain('<pdfaid:part>3</pdfaid:part>')
        ->and($pdf)->toContain('<pdfaid:conformance>B</pdfaid:conformance>')
        ->and($pdf)->toContain('<dc:title><rdf:Alt><rdf:li xml:lang="x-default">Rechnung RE-2026-0042</rdf:li></rdf:Alt></dc:title>')
        ->and($pdf)->toContain('<dc:creator><rdf:Seq><rdf:li>Muster GmbH</rdf:li></rdf:Seq></dc:creator>');
})->skip(fn () => ! fagusAvailable(), 'nforst/fagus or its binary is not installed.');

it('prints the letterhead, the footer with page numbers and the fonts of the template', function () {
    $pdf = fagusQuittio()->make(Scenarios::make('simple')->build())->format('pdf')->validate(false)->render()->pdf();
    $pages = fagusPages($pdf);
    $fonts = implode("\n", fagusPopplerOutput('pdffonts', $pdf));

    expect($pages)->toHaveCount(1)
        ->and($pages[0])->toContain('Muster GmbH · Hauptstraße 1 · 12345 Musterstadt')
        ->and($pages[0])->toContain('Seite 1 von 1')
        // The medium weights of the mPDF family geistmedium, the regular and bold ones of geist.
        ->and($fonts)->toContain('Geist-Regular')
        ->and($fonts)->toContain('Geist-Bold')
        ->and($fonts)->toContain('Geist-Medium')
        ->and($fonts)->toContain('Geist-SemiBold');
})->skip(fn () => ! fagusAvailable() || ! fagusPoppler(), 'nforst/fagus, its binary or poppler is not installed.');

it('places the address field of the letter layout in the page area, as content of the tagged PDF', function (string $layout, float $top) {
    $pdf = fagusQuittio()->make(Scenarios::make('simple')->build())->format('pdf')->presentation(new Presentation(letterLayout: $layout))->validate(false)->render()->pdf();
    $structure = implode("\n", fagusPopplerOutput('pdfinfo -struct-text', $pdf));
    // Top and left edge in millimetres of the first word on the sheet.
    $edges = static function (string $word) use ($pdf): array {
        foreach (fagusPopplerOutput('pdftotext -bbox', $pdf) as $line) {
            if (preg_match('/xMin="([0-9.]+)" yMin="([0-9.]+)".*>'.preg_quote($word, '/').'</', $line, $m) === 1) {
                return [(float) $m[2] / 72 * 25.4, (float) $m[1] / 72 * 25.4];
            }
        }

        return [0.0, 0.0];
    };
    [$senderTop] = $edges('Musterstadt');
    [$recipientTop, $left] = $edges('Kunde');

    // Not an artifact of the margin boxes: screen readers read the address.
    expect($structure)->toContain('"Muster GmbH · Hauptstraße 1 · 12345 Musterstadt"')
        ->and($structure)->toContain('"Kunde AG"')
        ->and($structure)->toContain('"54321 Beispielstadt"')
        ->and($left)->toBeGreaterThan(19.5)->toBeLessThan(20.5)
        ->and($senderTop)->toBeGreaterThan($top - 0.5)->toBeLessThan($top + 1.5)
        ->and($recipientTop)->toBeGreaterThan($top + 6.5)->toBeLessThan($top + 9.0);
})->with([
    'DIN 5008 form A' => ['din5008a', 27.0],
    'DIN 5008 form B' => ['din5008b', 45.0],
])->skip(fn () => ! fagusAvailable() || ! fagusPoppler(), 'nforst/fagus, its binary or poppler is not installed.');

it('breaks a long line table at the same rows as mPDF, with the carry-over and the running header', function () {
    $invoice = Scenarios::make('many-lines')->build();
    $fagus = fagusPages(fagusQuittio()->make($invoice)->format('pdf')->validate(false)->render()->pdf());
    $mpdf = fagusPages(Quittio::create([], [new CountryDE])->setRenderer(new MpdfRenderer)->make($invoice)->format('pdf')->validate(false)->render()->pdf());
    $rows = static fn (array $pages): array => array_map(static function (string $page): array {
        preg_match_all('/Artikel (\d+)/', $page, $m);

        return $m[1];
    }, $pages);

    expect($fagus)->toHaveCount(count($mpdf))
        ->and($rows($fagus))->toBe($rows($mpdf))
        ->and($fagus[0])->toMatch('/Fortsetzung auf Seite 2\s+Übertrag\s+612,60 €/u')
        ->and($fagus[0])->not->toContain('Übertrag von Seite')
        ->and($fagus[0])->not->toContain('Rechnung RE-2026-0042')
        ->and($fagus[1])->toMatch('/Übertrag von Seite 1\s+612,60 €/u')
        ->and($fagus[1])->toContain('Rechnung RE-2026-0042')
        ->and($fagus[1])->toContain('Seite 2 von 4')
        ->and(end($fagus))->not->toContain('Fortsetzung auf Seite')
        ->and(end($fagus))->toContain('Seite 4 von 4');
})->skip(fn () => ! fagusAvailable() || ! fagusPoppler(), 'nforst/fagus, its binary or poppler is not installed.');

it('embeds the ZUGFeRD XML with its profile, readable by the reader', function (string $profile, string $level) {
    $result = fagusQuittio()->make(Scenarios::make('simple')->build())->format('zugferd')->profile($profile)->validate(false)->render();
    $pdf = $result->pdf();
    $read = (new InvoiceReader)->read($pdf);

    expect((new PdfEmbeddedFileExtractor)->extract($pdf))->toBe(['factur-x.xml', $result->xml()])
        ->and($pdf)->toContain('<fx:ConformanceLevel>'.$level.'</fx:ConformanceLevel>')
        ->and($pdf)->toContain('<fx:DocumentFileName>factur-x.xml</fx:DocumentFileName>')
        ->and($pdf)->toContain('<pdfaid:part>3</pdfaid:part>')
        ->and($read->profile)->toBe($profile === 'basicwl' ? 'basic-wl' : $profile)
        ->and($read->data->number)->toBe('RE-2026-0042');
})->with([
    ['en16931', 'EN 16931'],
    ['extended', 'EXTENDED'],
    ['basicwl', 'BASIC WL'],
])->skip(fn () => ! fagusAvailable(), 'nforst/fagus or its binary is not installed.');

it('embeds XRechnung under its file name and conformance level', function () {
    $result = fagusQuittio()->make(Scenarios::make('simple')->buyerReference('04011000-12345-03'))
        ->format('zugferd')->profile('xrechnung')->validate(false)->render();
    $pdf = $result->pdf();

    expect((new PdfEmbeddedFileExtractor)->extract($pdf))->toBe(['xrechnung.xml', $result->xml()])
        ->and($pdf)->toContain('<fx:DocumentFileName>xrechnung.xml</fx:DocumentFileName>')
        ->and($pdf)->toContain('<fx:ConformanceLevel>XRECHNUNG</fx:ConformanceLevel>')
        // The version of XRechnung, as MpdfRenderer writes it.
        ->and($pdf)->toContain('<fx:Version>3.0</fx:Version>');
})->skip(fn () => ! fagusAvailable(), 'nforst/fagus or its binary is not installed.');

it('keeps PDF/A-3b according to veraPDF', function (string $format) {
    $quittio = Quittio::create(['validation' => ['verapdf' => ['enabled' => true]]], [new CountryDE])->setRenderer(new FagusRenderer);
    $result = $quittio->make(Scenarios::make('many-lines')->build())->format($format)->render();

    expect($result->validation()->levels)->toContain(ValidationLevel::L6)
        ->and($result->validation()->byLevel(ValidationLevel::L6))->toBe([]);
})->with(['zugferd', 'pdf'])->group('official')->skip(function () {
    exec('docker image inspect verapdf/cli 2>&1', $output, $code);

    return ! fagusAvailable() || ($code !== 0 && getenv('CI') !== 'true');
}, 'nforst/fagus or the Docker image verapdf/cli is not available.');

it('prints the watermark of a draft once on every page, without PDF/A', function () {
    $pdf = fagusQuittio()->make(Scenarios::make('many-lines')->build())->format('pdf')->option('watermark', 'ENTWURF')->validate(false)->render()->pdf();
    // The rotated letters come out of pdftotext with spaces between them.
    $pages = explode("\f", rtrim(implode('', fagusPopplerOutput('pdftotext -raw', $pdf)), "\f"));

    expect($pdf)->not->toContain('<pdfaid:part>')
        ->and($pages)->toHaveCount(4)
        ->and(array_map(static fn (string $page): int => substr_count((string) preg_replace('/\s+/', '', $page), 'ENTWURF'), $pages))->toBe([1, 1, 1, 1]);
})->skip(fn () => ! fagusAvailable() || ! fagusPoppler(), 'nforst/fagus, its binary or poppler is not installed.');

it('hands the logo to Fagus and nothing else from the file system or the network', function () {
    $logo = sys_get_temp_dir().'/quittio-fagus-logo.png';
    $image = imagecreatetruecolor(300, 120);
    imagepng($image, $logo);

    $withLogo = fagusQuittio()->make(Scenarios::make('simple')->build())->format('pdf')->presentation(new Presentation(logo: $logo))->validate(false)->render()->pdf();
    $other = (new FagusRenderer)->render(new RenderRequest(
        html: '<html><body><img src="'.__FILE__.'"><img src="https://example.com/logo.png"><img src="//example.com/logo.png"><p>Text</p></body></html>',
        title: 'Images',
        pdfa: false,
    ));
    $images = static fn (string $pdf): int => count(fagusPopplerOutput('pdfimages -list', $pdf)) - 2;

    // The logo and the GiroCode.
    expect($images($withLogo))->toBe(2)
        ->and($images($other))->toBe(0);
})->skip(fn () => ! fagusAvailable() || ! fagusPoppler() || ! extension_loaded('gd'), 'nforst/fagus, its binary, poppler or GD is not installed.');

it('renders the HTML of a request with its paper size', function () {
    $pdf = (new FagusRenderer)->render(new RenderRequest(html: '<p>Letter</p>', title: 'Letter', paper: 'Letter', pdfa: false));
    $file = tempnam(sys_get_temp_dir(), 'quittio-fagus-');
    file_put_contents((string) $file, $pdf);
    exec('pdfinfo '.escapeshellarg((string) $file), $info);
    unlink((string) $file);

    expect(implode("\n", $info))->toContain('Page size:       612 x 792 pts (letter)');
})->skip(fn () => ! fagusAvailable() || ! fagusPoppler(), 'nforst/fagus, its binary or poppler is not installed.');

it('takes the profile from the XML when the conformance level is unknown', function () {
    $xml = fagusQuittio()->make(Scenarios::make('simple')->build())->format('cii')->validate(false)->render()->xml();
    $pdf = (new FagusRenderer)->render(new RenderRequest(html: '<p>Invoice</p>', title: 'Invoice', embedded: new EmbeddedXml((string) $xml, conformanceLevel: null)));

    expect($pdf)->toContain('<fx:ConformanceLevel>EN 16931</fx:ConformanceLevel>');
})->skip(fn () => ! fagusAvailable(), 'nforst/fagus or its binary is not installed.');

it('fails with a PdfException when Fagus cannot render', function () {
    (new FagusRenderer(binary: '/nonexistent/fagus'))->render(new RenderRequest(html: '<p>x</p>', title: 'x'));
})->throws(PdfException::class, 'PDF rendering failed:')->skip(fn () => ! class_exists(Fagus::class), 'nforst/fagus is not installed.');

it('asks for the package nforst/fagus when it is missing', function () {
    // A process whose autoloader does not load the classes of nforst/fagus.
    $autoload = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2).'/autoload.php';
    $script = '$loader = require '.var_export($autoload, true).'; $loader->unregister();'
        .'spl_autoload_register(static function (string $class) use ($loader): void { if (! str_starts_with($class, "Fagus\\\\")) { $loader->loadClass($class); } });'
        .'try { new Quittio\Core\Pdf\Renderers\FagusRenderer; } catch (Quittio\Core\Exceptions\ConfigurationException $e) { echo get_class($e), ": ", $e->getMessage(); }';
    exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script), $output);

    expect(implode("\n", $output))->toBe(ConfigurationException::class.': The PDF renderer "fagus" needs the package nforst/fagus. Install it with: composer require nforst/fagus');
});
