<?php

declare(strict_types=1);

use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Tests\Support\Validate;

function lineNamed(string $name, ?string $id = null): Closure
{
    return function (LineBuilder $l) use ($name, $id): LineBuilder {
        $l->name($name)->quantity(1)->netPrice('10.00')->vat(VatCategory::StandardRate, '19');

        return $id === null ? $l : $l->id($id);
    };
}

it('skips identifiers set with id() when numbering lines', function (): void {
    $invoice = Invoices::base()
        ->line(lineNamed('A'))
        ->line(lineNamed('B', '1'))
        ->line(lineNamed('C'))
        ->build();

    expect(array_map(fn ($line) => $line->id, $invoice->lines))->toBe(['2', '1', '3']);
});

it('rejects line identifiers that are used twice', function (): void {
    Invoices::base()->line(lineNamed('A', '7'))->line(lineNamed('B', '7'))->build();
})->throws(InvalidInvoiceData::class, 'The line identifier "7" (BT-126) is used more than once');

it('rejects a line identifier that equals a sub line identifier', function (): void {
    Invoices::base()
        ->line(fn (LineBuilder $l): LineBuilder => $l->name('Bundle')->vat(VatCategory::StandardRate, '19')
            ->subLine(fn (LineBuilder $s): LineBuilder => $s->name('Part')->quantity(1)->netPrice('10.00')->vat(VatCategory::StandardRate, '19')))
        ->line(lineNamed('B', '1.1'))
        ->build();
})->throws(InvalidInvoiceData::class, '"1.1"');

it('warns about duplicate line identifiers in data from other sources', function (): void {
    $invoice = Invoices::base()->line(lineNamed('A'))->line(lineNamed('B'))->build();
    $invoice = $invoice->with(['lines' => [$invoice->lines[0], $invoice->lines[1]->with(['id' => '1'])]]);

    $result = Validate::data($invoice);

    expect(Validate::codes($result))->toContain('QUITTIO-LINE-ID-DUPLICATE')
        ->and(array_values(array_filter($result->messages, fn ($m) => $m->code === 'QUITTIO-LINE-ID-DUPLICATE')))->toHaveCount(1)
        ->and($result->isValid())->toBeTrue();
});
