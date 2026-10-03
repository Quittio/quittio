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
