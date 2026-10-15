<?php

declare(strict_types=1);

use Quittio\Laravel\QuittioServiceProvider;
use Quittio\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Laravel package test bootstrap
|--------------------------------------------------------------------------
| Every test runs inside a Testbench application with the service provider.
*/

uses(TestCase::class)->in(__DIR__);

function registerPreviewRoutes(): void
{
    $provider = app()->getProvider(QuittioServiceProvider::class) ?? throw new LogicException('The provider is not registered.');
    (fn () => $this->registerPreviewRoutes())->call($provider);
}

/**
 * Registers the preview routes as in the local environment.
 */
function enablePreview(): void
{
    config()->set('quittio.preview.enabled', true);
    app()->instance('env', 'local');
    registerPreviewRoutes();
}
