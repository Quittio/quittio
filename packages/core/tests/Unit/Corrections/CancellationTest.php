<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Config;
use Quittio\Core\Corrections\Cancellation;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Quittio;
use Quittio\Core\Support\Date;
use Quittio\Core\Testing\Scenarios;

function original(string $scenario = 'mixed-vat'): InvoiceData
{
    return (new En16931Calculator)->calculate(Scenarios::make($scenario)->build());
}

it('cancels with a credit note and positive amounts', function () {
    $original = original();
    $cancellation = (new En16931Calculator)->calculate(
        Cancellation::of($original, 'ST-1', reason: 'Doppelt berechnet', issueDate: Date::of(2026, 10, 5), locale: 'de')->build(),
    );

    expect($cancellation->typeCodeValue())->toBe('381')
        ->and($cancellation->number)->toBe('ST-1')
        ->and($cancellation->precedingInvoices[0]->number)->toBe('RE-2026-0042')
        ->and($cancellation->notes[0]->content)->toBe('Stornierung der Rechnung RE-2026-0042 vom 03.10.2026. Grund: Doppelt berechnet')
        ->and($cancellation->totals?->amountDue->getAmount()->toString())->toBe($original->totals?->amountDue->getAmount()->toString())
        ->and($original->number)->toBe('RE-2026-0042');
});

it('cancels with a negative invoice', function () {
    $original = original();
    $cancellation = (new En16931Calculator)->calculate(
        Cancellation::of($original, 'ST-2', strategy: 'negative_invoice', locale: 'en')->build(),
    );

    expect($cancellation->typeCodeValue())->toBe('380')
        ->and($cancellation->totals?->amountDue->getAmount()->toString())->toBe($original->totals?->amountDue->negated()->getAmount()->toString())
        ->and($cancellation->totals?->vatTotal?->getAmount()->toString())->toBe($original->totals?->vatTotal?->negated()->getAmount()->toString())
        ->and($cancellation->lines[0]->quantity->isNegative())->toBeTrue()
        ->and($cancellation->notes[0]->content)->toBe('Cancellation of invoice RE-2026-0042 of 03/10/2026.');
});

it('takes the strategy from the configuration', function () {
    $quittio = Quittio::create(['corrections' => ['cancellation_strategy' => 'negative_invoice']]);

    expect($quittio->cancellation(original('simple'), 'ST-3')->build()->typeCodeValue())->toBe('380')
        ->and($quittio->cancellation(original('simple'), 'ST-4', strategy: 'credit_note')->build()->typeCodeValue())->toBe('381')
        ->and(Quittio::create()->cancellation(original('simple'), 'ST-5')->build()->typeCodeValue())->toBe('381');
});

it('renders valid cancellations in both strategies', function (string $strategy, string $format) {
    $quittio = Quittio::create();
    $result = $quittio->make($quittio->cancellation(original(), 'ST-6', 'Test', $strategy))->format($format)->render();

    expect($result->validation()->errors())->toBe([]);
})->with(['credit_note', 'negative_invoice'])->with(['zugferd', 'ubl']);

it('rejects unknown strategies', function () {
    Cancellation::of(original('simple'), 'ST-7', strategy: 'storno');
})->throws(InvalidArgumentException::class);

it('rejects unknown strategies in the configuration', function () {
    new Config(cancellationStrategy: 'storno');
})->throws(ConfigurationException::class);
