<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\I18n\ArrayTranslator;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Quittio;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcasterChain;

afterEach(fn () => Quittio::onResetState('test.counter', static function (): void {}));

it('resets the process-wide state of the core and of registered extensions', function () {
    $translator = new ArrayTranslator;
    Translations::use($translator);
    $chain = new SnapshotUpcasterChain;
    SnapshotUpcasterChain::useDefault($chain);
    InvoiceBuilder::useDefaultVat(static fn (): never => throw new LogicException('not reset'));
    $calls = 0;
    Quittio::onResetState('test.counter', static function () use (&$calls): void {
        $calls++;
    });

    Quittio::resetState();

    expect(Translations::translator())->not->toBe($translator)
        ->and(SnapshotUpcasterChain::default())->not->toBe($chain)
        ->and($calls)->toBe(1)
        // Without a default VAT a line without VAT treatment fails with BG-30, not with the closure.
        ->and(fn () => InvoiceBuilder::make()->line('Item', 1, '10.00')->build())->toThrow(InvalidInvoiceData::class, 'BG-30');
});

it('replaces a registration under the same key', function () {
    $calls = [];
    Quittio::onResetState('test.counter', static function () use (&$calls): void {
        $calls[] = 'first';
    });
    Quittio::onResetState('test.counter', static function () use (&$calls): void {
        $calls[] = 'second';
    });

    Quittio::resetState();

    expect($calls)->toBe(['second']);
});
