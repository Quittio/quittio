<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\I18n\Translations;
use Quittio\Country\DE\Dunning\BaseRates;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Support\LaravelTranslator;

/**
 * The Laravel translator behind the translator bridge of the core.
 */
function bridgedTranslator(): object
{
    $bridge = Translations::translator();
    expect($bridge)->toBeInstanceOf(LaravelTranslator::class);

    return (fn () => $this->translator)->call($bridge);
}

it('points the process-wide state to the application booted last', function () {
    $first = $this->app;
    $rates = (string) config('quittio-de.base_rate_file');
    file_put_contents($rates, (string) json_encode(['2099-01-01' => '9.99']));
    putenv('QUITTIO_DEFAULT_VAT=de.reduced_7');

    try {
        // A second application in the same process, as in a queue worker that boots again.
        $second = $this->createApplication();
    } finally {
        putenv('QUITTIO_DEFAULT_VAT');
        unlink($rates);
    }

    try {
        expect(Container::getInstance())->toBe($second)
            ->and(bridgedTranslator())->toBe($second->make('translator'))
            ->and(bridgedTranslator())->not->toBe($first->make('translator'))
            ->and(BaseRates::registered())->toHaveKey('2099-01-01')
            // Lines without VAT use the default VAT of the second application.
            ->and($second->make(QuittioManager::class)->defaultVat('DE', false)?->key())->toBe('de.reduced')
            ->and(InvoiceBuilder::make()->seller(fn ($s) => $s->name('S')->address('Weg 1', '12345', 'Stadt', 'DE'))->buyer(fn ($b) => $b->name('B')->address('Weg 2', '12345', 'Stadt', 'DE'))
                ->number('1')->issueDate('2026-10-01')->line('Buch', '10.00', 1)->build()->lines[0]->vat->rate?->isEqualTo('7'))->toBeTrue();
    } finally {
        $second->flush();
        Container::setInstance($first);
    }
});
