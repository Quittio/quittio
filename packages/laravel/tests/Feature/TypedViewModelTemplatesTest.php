<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;
use Quittio\Country\DE\CountryDE;
use Quittio\Laravel\Facades\Quittio;

/*
 * Blade templates published before the typed view model read it with array syntax; templates
 * written against the typed nodes use object syntax and the node classes. Both must print
 * the same and record the same reads for the mandatory field guard.
 */

beforeEach(function () {
    view()->addNamespace('fixtures', __DIR__.'/../Fixtures/views');
});

it('renders a Blade template with array syntax like one with the typed nodes', function (string $scenario) {
    $render = function (string $view) use ($scenario): array {
        $model = (new ViewModelFactory)->build(
            (new En16931Calculator)->calculate(Scenarios::make($scenario)->build()),
            new ViewContext(locale: 'de', country: new CountryDE),
        );
        $html = view($view, ['invoice' => $model])->render();
        $paths = $model->accessLog()->all();
        sort($paths);

        return [(string) preg_replace('/\s+/', ' ', $html), $paths];
    };

    [$legacy, $legacyReads] = $render('fixtures::compat.legacy');
    [$typed, $typedReads] = $render('fixtures::compat.typed');

    expect($typed)->toBe($legacy)
        ->and($typedReads)->toBe($legacyReads)
        ->and($legacy)->toContain(Scenarios::make($scenario)->build()->number);
})->with(['simple', 'many-lines', 'reverse-charge', 'cancellation', 'sub-lines', 'allowances', 'foreign-currency']);

it('passes the mandatory field guard with both Blade templates', function () {
    config()->set('quittio.pdf.templates', ['fixtures::*']);
    config()->set('quittio.validation.mandatory_field_guard', 'exception');
    Quittio::flush();
    $render = fn (string $template) => (string) preg_replace('/\s+/', ' ', (string) Quittio::make(Scenarios::make('simple')->build())
        ->format('html')->validate(false)->template($template)->render()->html());

    expect($render('fixtures::compat.typed'))->toBe($render('fixtures::compat.legacy'));
});
