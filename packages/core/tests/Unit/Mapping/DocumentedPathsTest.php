<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\DocumentedPaths;

it('finds every documented CII and UBL path in the XML of the sample invoices', function () {
    $profiles = ProfileRegistry::withDefaults();
    $missing = [];
    foreach (Scenarios::names() as $name) {
        $data = (new En16931Calculator)->calculate(Scenarios::make($name)->build());
        $missing += DocumentedPaths::missing($data, $profiles->get('extended'), $profiles->get('en16931'));
    }
    ksort($missing);

    expect($missing)->toBe([]);
});
