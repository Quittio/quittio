<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Reading\ReadResult;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;

/**
 * CII or UBL with a document allowance (50.00), a document charge (20.00),
 * a line allowance (10.00) and a line charge (5.00).
 */
function chargeIndicatorXml(string $syntax): string
{
    $invoice = (new En16931Calculator)->calculate(Invoices::base()
        ->line(fn ($l) => $l
            ->name('Consulting')
            ->quantity(10, UnitCode::Hour)
            ->netPrice('100.00')
            ->vat(VatCategory::StandardRate, '19')
            ->allowance('10.00', reason: 'Loyalty')
            ->charge('5.00', reason: 'Express'))
        ->allowance(VatCategory::StandardRate, '19', '50.00', reason: 'Discount')
        ->charge(VatCategory::StandardRate, '19', '20.00', reason: 'Freight')
        ->build());
    $profile = ProfileRegistry::withDefaults()->get('en16931');

    return $syntax === 'cii' ? (new CiiWriter)->serialize($invoice, $profile) : (new UblWriter)->serialize($invoice, $profile);
}

/**
 * Replaces every charge indicator value of the given kind.
 */
function chargeIndicatorReplace(string $xml, string $syntax, string $from, string $to): string
{
    $element = $syntax === 'cii' ? ['<udt:Indicator>', '</udt:Indicator>'] : ['<cbc:ChargeIndicator>', '</cbc:ChargeIndicator>'];
    $result = str_replace($element[0].$from.$element[1], $element[0].$to.$element[1], $xml, $count);
    expect($count)->toBe(2, sprintf('Expected two %s indicators.', $from));

    return $result;
}

/**
 * @return list<string>
 */
function chargeIndicatorCodes(ReadResult $result, ?Severity $severity = null): array
{
    return array_values(array_map(
        fn (ValidationMessage $f): string => $f->code,
        array_filter($result->findings, fn (ValidationMessage $f): bool => $severity === null || $f->severity === $severity),
    ));
}

dataset('syntaxes', ['cii', 'ubl']);

it('reads all xs:boolean spellings of the charge indicator', function (string $syntax, string $true, string $false) {
    $xml = chargeIndicatorXml($syntax);
    $xml = chargeIndicatorReplace($xml, $syntax, 'true', $true);
    $xml = chargeIndicatorReplace($xml, $syntax, 'false', $false);

    $result = (new InvoiceReader)->read($xml);
    $line = $result->data->lines[0];

    expect(array_map(fn ($a) => (string) $a->amount?->getAmount(), $result->data->allowances))->toBe(['50.00'])
        ->and(array_map(fn ($c) => (string) $c->amount?->getAmount(), $result->data->charges))->toBe(['20.00'])
        ->and(array_map(fn ($a) => (string) $a->amount?->getAmount(), $line->allowances))->toBe(['10.00'])
        ->and(array_map(fn ($c) => (string) $c->amount?->getAmount(), $line->charges))->toBe(['5.00'])
        ->and(chargeIndicatorCodes($result, Severity::Error))->toBe([]);
})->with('syntaxes')->with([
    'true/false' => ['true', 'false'],
    '1/0' => ['1', '0'],
    'with white space' => [' true ', "\n  false\n"],
]);

it('reports an invalid charge indicator and does not read the entry', function (string $syntax) {
    $xml = chargeIndicatorReplace(chargeIndicatorXml($syntax), $syntax, 'true', 'yes');

    $result = (new InvoiceReader)->read($xml);
    $invalid = array_values(array_filter($result->findings, fn (ValidationMessage $f): bool => $f->code === 'QUITTIO-READER-CHARGE-INDICATOR'));

    expect($result->data->charges)->toBe([])
        ->and($result->data->lines[0]->charges)->toBe([])
        ->and($result->data->allowances)->toHaveCount(1)
        ->and($result->data->lines[0]->allowances)->toHaveCount(1)
        ->and(array_map(fn (ValidationMessage $f) => [$f->severity, $f->bt], $invalid))->toBe([
            [Severity::Error, 'BG-27'],
            [Severity::Error, 'BG-20'],
        ]);
})->with('syntaxes');
