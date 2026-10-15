<?php

declare(strict_types=1);

use Quittio\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Monorepo test bootstrap
|--------------------------------------------------------------------------
| Pest loads only this file. Each package keeps its own tests/Pest.php so
| that it stays testable after the split; they are included here.
|
| tests/Monorepo holds the tests that need the whole repository: the
| documentation in docs/ and the sources of all packages.
*/

foreach (['core', 'country-de', 'laravel'] as $package) {
    $bootstrap = dirname(__DIR__).'/packages/'.$package.'/tests/Pest.php';
    if (is_file($bootstrap)) {
        require $bootstrap;
    }
}

uses(TestCase::class)->in('Monorepo');
