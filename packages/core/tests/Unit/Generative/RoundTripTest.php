<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Tests\Support\RandomInvoices;
use Quittio\Core\Tests\Support\RoundTrip;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;

/**
 * Seeds of the generated invoices: fixed, so that a failure can be reproduced with
 * RandomInvoices::make($seed).
 *
 * @return array<string, array{int}>
 */
function generativeSeeds(int $count, int $offset = 0): array
{
    $seeds = [];
    for ($seed = $offset + 1; $seed <= $offset + $count; $seed++) {
        $seeds['seed '.$seed] = [$seed];
    }

    return $seeds;
}

/**
 * @return list<string>
 */
function roundTripErrors(array $findings): array
{
    return array_values(array_map(static fn (ValidationMessage $m): string => $m->code.': '.$m->message, array_filter($findings, static fn (ValidationMessage $m): bool => $m->severity === Severity::Error)));
}

it('reads generated invoices back from CII and UBL without loss', function (int $seed) {
    $invoice = (new En16931Calculator)->calculate(RandomInvoices::make($seed)->build());
    $profile = ProfileRegistry::withDefaults()->get('en16931');
    $expected = RoundTrip::comparable($invoice->with(['businessProcess' => $invoice->businessProcess ?? $profile->defaultBusinessProcess()]));

    foreach ([new CiiWriter, new UblWriter] as $writer) {
        $read = (new InvoiceReader)->read($writer->serialize($invoice, $profile));

        expect(roundTripErrors($read->findings))->toBe([])
            ->and(RoundTrip::comparable($read->data))->toEqual($expected);
    }
})->with(generativeSeeds(200));

it('reads generated invoices with sub lines back from CII EXTENDED without loss', function (int $seed) {
    $invoice = (new En16931Calculator)->calculate(RandomInvoices::make($seed, subLines: true)->build());
    $profile = ProfileRegistry::withDefaults()->get('extended');

    $read = (new InvoiceReader)->read((new CiiWriter)->serialize($invoice, $profile));

    expect(roundTripErrors($read->findings))->toBe([])
        ->and(RoundTrip::comparable($read->data))->toEqual(RoundTrip::comparable($invoice->with(['businessProcess' => $invoice->businessProcess ?? $profile->defaultBusinessProcess()])));
})->with(generativeSeeds(50, 1000));
