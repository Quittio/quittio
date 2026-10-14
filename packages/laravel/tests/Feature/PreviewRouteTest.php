<?php

declare(strict_types=1);

use Quittio\Laravel\Http\PreviewController;
use Quittio\Laravel\QuittioServiceProvider;

function enablePreview(): void
{
    config()->set('quittio.preview.enabled', true);
    app()->getProvider(QuittioServiceProvider::class)?->registerPreviewRoutes();
}

it('lists the scenarios', function () {
    enablePreview();
    $this->get('/_quittio/preview')
        ->assertOk()
        ->assertSee('reverse-charge')
        ->assertSee('/_quittio/preview/simple?format=pdf', false);
});

it('shows a scenario as HTML and PDF', function () {
    enablePreview();
    $this->get('/_quittio/preview/simple')
        ->assertOk()
        ->assertSee('Webentwicklung')
        ->assertSee('VORSCHAU');

    $pdf = $this->get('/_quittio/preview/simple?format=pdf')->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($pdf->headers->get('Content-Disposition'))->toStartWith('inline;');
});

it('returns 404 for unknown scenarios', function () {
    enablePreview();
    $this->get('/_quittio/preview/unknown')->assertNotFound();
});

it('is disabled outside the local environment', function () {
    $this->get('/_quittio/preview')->assertNotFound();
    expect(app('router')->has('quittio.preview.index'))->toBeFalse()
        ->and(class_exists(PreviewController::class))->toBeTrue();
});

it('renders only allowed templates, never files or other views', function (string $template) {
    enablePreview();
    view()->addLocation(__DIR__.'/../Fixtures/views');
    $marker = sys_get_temp_dir().'/quittio-preview-marker-'.bin2hex(random_bytes(4));
    $file = $marker.'.php';
    file_put_contents($file, '<?php file_put_contents('.var_export($marker, true).', "x");');
    $template = str_replace('{file}', $file, $template);

    $this->get('/_quittio/preview/simple?template='.rawurlencode($template))->assertNotFound();
    expect(is_file($marker))->toBeFalse();
})->with([
    'absolute PHP file' => ['{file}'],
    'hosts file' => ['/etc/hosts'],
    'dot env' => ['../.env'],
    'application view' => ['invoice'],
    'other namespace' => ['quittio::preview.index'],
]);

it('renders the shipped and the configured template', function () {
    enablePreview();
    config()->set('quittio.pdf.template', 'quittio::templates.default.layout');

    $this->get('/_quittio/preview/simple?template=quittio::templates.default.layout')->assertOk()->assertSee('Webentwicklung');
    $this->get('/_quittio/preview/simple?template=default')->assertOk();
    $this->get('/_quittio/preview/simple?locale=..%2F..%2Fx')->assertNotFound();
    $this->get('/_quittio/preview/simple?locale=en')->assertOk()->assertSee('PREVIEW');
});

it('renders the views that rendering allows', function () {
    enablePreview();
    view()->addNamespace('fixtures', __DIR__.'/../Fixtures/views');
    config()->set('quittio.validation.mandatory_field_guard', 'off');
    Quittio\Laravel\Facades\Quittio::flush();

    $this->get('/_quittio/preview/simple?template=fixtures::invoice')->assertNotFound();

    config()->set('quittio.pdf.templates', ['fixtures::*']);
    $this->get('/_quittio/preview/simple?template=fixtures::invoice')->assertOk()->assertSee('RE-2026-0042');
    $this->get('/_quittio/preview/simple?template=fixtures::missing')->assertNotFound();
});

it('previews with the configured design', function () {
    enablePreview();
    config()->set('quittio.pdf.colors.primary', '#abcdef');
    config()->set('quittio.pdf.texts.intro', 'Configured intro');
    Quittio\Laravel\Facades\Quittio::flush();

    $this->get('/_quittio/preview/simple')->assertOk()->assertSee('#abcdef')->assertSee('Configured intro')->assertSee('VORSCHAU');
});
