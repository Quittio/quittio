<?php

declare(strict_types=1);

use Quittio\Core\I18n\Translations;
use Quittio\Core\Tests\Support\Official;

uses()->beforeEach(function () {
    Translations::reset();
})->in(__DIR__);

// The official validation tests use a helper of the core tests, which only the monorepo has.
if (! class_exists(Official::class)) {
    require __DIR__.'/Standalone/Official.php';
}
