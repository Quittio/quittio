<?php

declare(strict_types=1);

use Quittio\Core\Quittio;
use Quittio\Country\DE\Dunning\BaseRates;

it('forgets registered base rates with Quittio::resetState()', function () {
    BaseRates::register(['2099-01-01' => '9.99']);

    Quittio::resetState();

    expect(BaseRates::registered())->toBe([]);
});
