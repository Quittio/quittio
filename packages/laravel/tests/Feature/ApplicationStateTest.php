<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Quittio\Country\DE\Dunning\BaseRates;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Testing\InteractsWithQuittio;

it('keeps the built core while the configuration is unchanged', function () {
    $core = Quittio::core();

    expect(Quittio::core())->toBe($core);
});

it('builds a new core when the configuration changes', function () {
    $core = Quittio::core();
    config()->set('quittio.defaults.profile', 'xrechnung-3.0');

    expect(Quittio::core())->not->toBe($core)
        ->and(Quittio::core()->config()->profile)->toBe('xrechnung-3.0');
});

it('reads the configuration of the current application, as under Octane', function () {
    $manager = app(QuittioManager::class);
    $base = Container::getInstance();
    // Octane serves each request from a clone of the booted application with its own configuration.
    $request = clone $base;
    $request->instance('config', new Repository(array_replace_recursive(config()->all(), ['quittio' => ['defaults' => ['profile' => 'xrechnung-3.0']]])));
    Container::setInstance($request);

    try {
        expect($manager->core()->config()->profile)->toBe('xrechnung-3.0');
    } finally {
        Container::setInstance($base);
    }

    expect($manager->core()->config()->profile)->not->toBe('xrechnung-3.0');
});

it('resets the state after each test that uses InteractsWithQuittio', function () {
    $test = new class
    {
        use InteractsWithQuittio;

        public function resetNow(): void
        {
            $this->tearDownInteractsWithQuittio();
        }
    };
    BaseRates::register(['2099-01-01' => '9.99']);

    $test->resetNow();

    expect(BaseRates::registered())->toBe([]);
});
