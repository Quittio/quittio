<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Country\DE\CountryDE;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Seller;
use Quittio\Laravel\Support\CountryPackDiscovery;
use Quittio\Laravel\Support\LaravelTranslator;

it('registers the manager, configuration and views', function () {
    expect(app(QuittioManager::class))->toBe(app('quittio'))
        ->and(Quittio::getFacadeRoot())->toBeInstanceOf(QuittioManager::class)
        ->and(config('quittio.pdf.renderer'))->toBe('mpdf')
        ->and(view()->exists('quittio::templates.default.layout'))->toBeTrue()
        ->and(view()->exists('quittio::templates.default.partials.totals'))->toBeTrue();
});

it('bridges labels to the Laravel translator', function () {
    expect(Translations::translator())->toBeInstanceOf(LaravelTranslator::class)
        ->and(__('quittio::labels.amount_due', [], 'de'))->toBe('Zahlbetrag')
        ->and(Translations::translator()->get('quittio::labels.amount_due', [], 'en'))->toBe('Amount due');
});

it('registers configured country packs and their profiles', function () {
    expect(array_keys(Quittio::countries()->all()))->toBe(['DE'])
        ->and(Quittio::profiles()->has('xrechnung'))->toBeTrue()
        ->and(Quittio::formats()->has('xrechnung-cii'))->toBeTrue();
});

it('builds the seller from the configuration', function () {
    $seller = Seller::fromConfig();

    expect($seller->name)->toBe('Muster GmbH')
        ->and($seller->vatId)->toBe('DE123456789')
        ->and($seller->address->city)->toBe('Musterstadt')
        ->and($seller->electronicAddress?->value)->toBe('rechnung@muster.de');
});

it('maps the configuration to the core', function () {
    config()->set('quittio.validation.levels', ['php']);
    config()->set('quittio.pdf.colors.primary', '#ff0000');
    config()->set('quittio.validation.mandatory_field_guard', null);
    $config = app(QuittioManager::class)->flush()->core()->config();

    expect($config->validationLevels)->not->toContain(ValidationLevel::L4)
        ->and($config->presentation?->colors?->primary)->toBe('#ff0000')
        ->and($config->mandatoryFields)->toBe('exception');
});

it('rejects unknown extension types and renderers', function () {
    Quittio::extend('unknown', 'x', fn () => new stdClass);
})->throws(InvalidArgumentException::class);

it('discovers country packs from Composer metadata', function () {
    $vendor = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2);

    expect(CountryPackDiscovery::discover($vendor))->toContain(CountryDE::class)
        ->and(CountryPackDiscovery::discover('/nonexistent/vendor'))->toBe([]);
});
