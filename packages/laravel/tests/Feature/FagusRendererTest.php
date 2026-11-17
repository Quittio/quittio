<?php

declare(strict_types=1);

use Fagus\Engine;
use Fagus\Fagus;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Pdf\Renderers\CallbackRenderer;
use Quittio\Core\Pdf\Renderers\FagusRenderer;
use Quittio\Core\Quittio as CoreQuittio;
use Quittio\Core\Testing\Scenarios;
use Quittio\Laravel\Facades\Quittio;

/*
| pdf.renderer "fagus": the built-in driver for the optional package nforst/fagus, with the Blade
| templates. Rendering tests are skipped when the package, its binary or poppler is missing.
*/

function laravelFagusAvailable(): bool
{
    static $available = null;
    if ($available === null) {
        exec('command -v pdftotext', $output, $code);
        try {
            $available = $code === 0 && class_exists(Fagus::class) && (new Engine)->version() !== '';
        } catch (Throwable) {
            $available = false;
        }
    }

    return $available;
}

/**
 * Text of each page (pdftotext -layout).
 *
 * @return list<string>
 */
function laravelFagusPages(string $pdf): array
{
    $file = tempnam(sys_get_temp_dir(), 'quittio-fagus-');
    file_put_contents((string) $file, $pdf);
    exec(sprintf('pdftotext -layout %s -', escapeshellarg((string) $file)), $lines);
    unlink((string) $file);

    return explode("\f", rtrim(implode("\n", $lines), "\f"));
}

function laravelRenderer(): object
{
    return (new ReflectionProperty(CoreQuittio::class, 'renderer'))->getValue(Quittio::flush()->core());
}

it('builds the Fagus renderer from pdf.renderers.fagus and the font settings', function () {
    config()->set('quittio.pdf.renderer', 'fagus');
    config()->set('quittio.pdf.renderers.fagus', ['binary' => '/opt/fagus/fagus', 'timeout' => 20]);
    config()->set('quittio.pdf.font', 'inter');
    config()->set('quittio.pdf.fonts', ['corporate' => ['R' => 'Corporate-Regular.ttf']]);
    config()->set('quittio.pdf.font_directories', ['/configured/fonts']);

    $renderer = laravelRenderer();
    $value = static fn (string $property): mixed => (new ReflectionProperty(FagusRenderer::class, $property))->getValue($renderer);

    expect($renderer)->toBeInstanceOf(FagusRenderer::class)
        ->and($value('binary'))->toBe('/opt/fagus/fagus')
        ->and($value('timeout'))->toBe(20.0)
        ->and($value('defaultFont'))->toBe('inter')
        ->and($value('fonts'))->toBe(['corporate' => ['R' => 'Corporate-Regular.ttf']])
        ->and($value('fontDirectories'))->toBe(['/configured/fonts']);
})->skip(fn () => ! class_exists(Fagus::class), 'nforst/fagus is not installed.');

it('refuses a timeout of the Fagus renderer that is no number', function () {
    config()->set('quittio.pdf.renderer', 'fagus');
    config()->set('quittio.pdf.renderers.fagus.timeout', 'long');

    Quittio::flush()->core();
})->throws(ConfigurationException::class, 'quittio.pdf.renderers.fagus.timeout must be a number of seconds.');

it('names the built-in renderers for an unknown one', function () {
    config()->set('quittio.pdf.renderer', 'wkhtmltopdf');

    Quittio::flush()->core();
})->throws(ConfigurationException::class, 'Unknown PDF renderer "wkhtmltopdf". Use "mpdf" or "fagus", or register it with Quittio::extend(\'renderer\', ...).');

it('lets an extension replace the built-in Fagus renderer', function () {
    Quittio::extend('renderer', 'fagus', fn (): CallbackRenderer => new CallbackRenderer(fn (string $html): string => '%PDF-1.7'));
    config()->set('quittio.pdf.renderer', 'fagus');

    expect(laravelRenderer())->toBeInstanceOf(CallbackRenderer::class);
});

it('renders the Blade template with Fagus at the page breaks of mPDF', function () {
    $invoice = Scenarios::make('many-lines')->build();
    $mpdf = laravelFagusPages(Quittio::make($invoice)->format('pdf')->validate(false)->render()->pdf());
    config()->set('quittio.pdf.renderer', 'fagus');
    $fagus = laravelFagusPages(Quittio::flush()->make($invoice)->format('pdf')->validate(false)->render()->pdf());
    $rows = static fn (array $pages): array => array_map(static function (string $page): array {
        preg_match_all('/Artikel (\d+)/', $page, $m);

        return $m[1];
    }, $pages);

    expect($fagus)->toHaveCount(count($mpdf))
        ->and($rows($fagus))->toBe($rows($mpdf))
        ->and($fagus[0])->toMatch('/Fortsetzung auf Seite 2\s+Übertrag\s+612,60 €/u')
        ->and($fagus[1])->toMatch('/Übertrag von Seite 1\s+612,60 €/u')
        ->and($fagus[1])->toContain('Rechnung RE-2026-0042')
        ->and($fagus[1])->toContain('Seite 2 von '.count($mpdf));
})->skip(fn () => ! laravelFagusAvailable(), 'nforst/fagus, its binary or poppler is not installed.');

it('renders ZUGFeRD with the Blade template and Fagus', function () {
    config()->set('quittio.pdf.renderer', 'fagus');
    $result = Quittio::flush()->make(Scenarios::make('simple')->build())->format('zugferd')->render();

    expect((new PdfEmbeddedFileExtractor)->extract($result->pdf()))->toBe(['factur-x.xml', $result->xml()])
        ->and($result->pdf())->toContain('<pdfaid:part>3</pdfaid:part>')
        ->and(laravelFagusPages($result->pdf())[0])->toContain('Seite 1 von 1');
})->skip(fn () => ! laravelFagusAvailable(), 'nforst/fagus, its binary or poppler is not installed.');

it('renders documents with the Blade template and Fagus', function () {
    config()->set('quittio.pdf.renderer', 'fagus');
    $pdf = Quittio::flush()->paymentReminder(1)
        ->number('MA-2026-0007')
        ->issueDate('2026-11-20')
        ->buyer(fn ($b) => $b->name('Kunde AG')->address('Kundenweg 5', '80331', 'München', 'DE'))
        ->openItem('RE-2026-0042', '2026-10-05', '1960.95', '2026-11-04')
        ->fee('Mahngebühr', '5.00')
        ->render()->pdf();
    $pages = laravelFagusPages((string) $pdf);

    expect($pages)->toHaveCount(1)
        ->and($pages[0])->toContain('1. Mahnung')
        ->and($pages[0])->toContain('RE-2026-0042')
        ->and($pages[0])->toContain('Seite 1 von 1');
})->skip(fn () => ! laravelFagusAvailable(), 'nforst/fagus, its binary or poppler is not installed.');
