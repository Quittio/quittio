<?php

declare(strict_types=1);

use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Tests\Support\Invoices;

/**
 * @return array<string, Closure>
 */
function coerciveCalls(): array
{
    return require __DIR__.'/../../Fixtures/coercive/calls.php';
}

it('rejects floats in coercive mode instead of cutting them to integers', function (string $call): void {
    $line = Invoices::simple()->build()->lines[0];

    expect(fn () => coerciveCalls()[$call]($line))->toThrow(InvalidArgumentException::class, 'Floats are not accepted');
})->with(array_keys(coerciveCalls()));

it('rejects floats in strict mode with the same message', function (): void {
    (new LineBuilder)->netPrice(19.99);
})->throws(InvalidArgumentException::class, 'Floats are not accepted for amounts, prices, rates and quantities (19.99 given)');

it('still accepts integers, strings and Brick numbers', function (): void {
    $invoice = Invoices::base()
        ->line(fn (LineBuilder $l): LineBuilder => $l->name('A')->quantity(2)->netPrice('19.99')->vat(Quittio\Core\Enums\VatCategory::StandardRate, 19))
        ->line(fn (LineBuilder $l): LineBuilder => $l->name('B')->quantity(Brick\Math\BigDecimal::of('1.5'))->netPrice(10)->vat(Quittio\Core\Enums\VatCategory::StandardRate, '19'))
        ->build();

    expect((string) $invoice->lines[0]->price->netPrice)->toBe('19.99')
        ->and((string) $invoice->lines[1]->quantity)->toBe('1.5');
});
