<?php

declare(strict_types=1);

use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\TotalsCalculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Xml\Cii\CiiWriter;

function recalculationXml(): string
{
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->build());

    return (new CiiWriter)->serialize($invoice, ProfileRegistry::withDefaults()->get('en16931'));
}

function failingCalculator(Throwable $error): TotalsCalculator
{
    return new class($error) implements TotalsCalculator
    {
        public function __construct(private readonly Throwable $error) {}

        public function calculate(InvoiceData $invoice): InvoiceData
        {
            throw $this->error;
        }
    };
}

it('reports read data that cannot be recalculated', function (): void {
    $reader = new InvoiceReader(calculator: failingCalculator(new CalculationException('Mixed currencies.')));

    $result = $reader->read(recalculationXml());
    $codes = array_map(fn ($f) => $f->code, $result->findings);

    expect($codes)->toContain('QUITTIO-READER-RECALCULATION');
});

it('does not hide programming errors of the calculator', function (): void {
    $reader = new InvoiceReader(calculator: failingCalculator(new TypeError('Bug in a custom calculator.')));

    $reader->read(recalculationXml());
})->throws(TypeError::class, 'Bug in a custom calculator.');
