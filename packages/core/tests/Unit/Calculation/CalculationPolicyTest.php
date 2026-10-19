<?php

declare(strict_types=1);

use Brick\Money\Money;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Calculation\CalculationPolicy;
use Quittio\Core\Calculation\DeclaredAmountsMode;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\TotalsCalculator;
use Quittio\Core\Config;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Meta;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Quittio;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Support\Amount;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Xml\Cii\CiiWriter;

/**
 * A received invoice of a system that rounds per line: 3 × 33.333 declared as $line1 (Quittio:
 * 100.00) and VAT $vat instead of 28.50.
 */
function policyForeign(string $line1 = '99.99', string $vat = '28.49'): InvoiceData
{
    $calculated = (new En16931Calculator)->calculate(Invoices::base()
        ->line(fn ($l) => $l->name('Widget')->quantity(3, UnitCode::Piece)->netPrice('33.333')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('Service')->quantity(1, UnitCode::Piece)->netPrice('50.00')->vat(VatCategory::StandardRate, '19'))
        ->build());
    $eur = fn (string $amount): Money => Amount::of($amount, 'EUR');
    $net = $eur($line1)->plus($eur('50.00'));
    $gross = $net->plus($eur($vat));
    $foreign = $calculated->with([
        'lines' => [$calculated->lines[0]->with(['netAmount' => $eur($line1)]), $calculated->lines[1]],
        'vatBreakdown' => [$calculated->vatBreakdown[0]->with(['taxableAmount' => $net, 'taxAmount' => $eur($vat)])],
        'totals' => $calculated->totals?->with(['lineNetSum' => $net, 'netTotal' => $net, 'vatTotal' => $eur($vat), 'grossTotal' => $gross, 'amountDue' => $gross]),
    ]);

    return (new InvoiceReader)->read((new CiiWriter)->serialize($foreign, ProfileRegistry::withDefaults()->get('en16931')))->data;
}

/**
 * @return array{string, string, bool}  BT-131 of line 1, BT-117 and whether QUITTIO-DECLARED-AMOUNTS applies
 */
function policyResult(DeclaredAmountsMode $mode, InvoiceData $foreign): array
{
    $calculator = new En16931Calculator(policy: new CalculationPolicy($mode));
    $invoice = $calculator->calculate(InvoiceBuilder::fromUsing($foreign, $calculator)->note('Corrected')->build());

    return [
        (string) $invoice->lines[0]->netAmount?->getAmount(),
        (string) $invoice->vatBreakdown[0]->taxAmount->getAmount(),
        $invoice->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED),
    ];
}

it('keeps declared amounts within the tolerances by default', function () {
    expect(policyResult(DeclaredAmountsMode::Keep, policyForeign()))->toBe(['99.99', '28.49', false]);
});

it('recalculates declared amounts and reports it', function () {
    expect(policyResult(DeclaredAmountsMode::Recalculate, policyForeign()))->toBe(['100.00', '28.50', true]);
});

it('rejects declared amounts outside the tolerances', function () {
    expect(policyResult(DeclaredAmountsMode::Reject, policyForeign()))->toBe(['99.99', '28.49', false])
        ->and(policyResult(DeclaredAmountsMode::Keep, policyForeign('99.50', '28.40')))->toBe(['100.00', '28.50', true])
        ->and(fn () => policyResult(DeclaredAmountsMode::Reject, policyForeign('99.50', '28.40')))
        ->toThrow(CalculationException::class, 'declared amounts mode "reject"');
});

it('reads the mode from the configuration', function () {
    expect(Config::fromArray(['calculation' => ['declared_amounts' => 'recalculate']])->calculation->declaredAmounts)->toBe(DeclaredAmountsMode::Recalculate)
        ->and(Config::fromArray([])->calculation->declaredAmounts)->toBe(DeclaredAmountsMode::Keep)
        ->and(fn () => Config::fromArray(['calculation' => ['declared_amounts' => 'round']]))->toThrow(ConfigurationException::class, 'Unknown declared amounts mode');
});

it('edits invoices with the calculator of the instance', function () {
    $quittio = Quittio::create(['calculation' => ['declared_amounts' => 'recalculate']]);
    $invoice = $quittio->calculator()->calculate($quittio->edit(policyForeign())->build());

    $default = Quittio::create();
    $kept = $default->calculator()->calculate($default->edit(policyForeign())->build());

    expect((string) $invoice->lines[0]->netAmount?->getAmount())->toBe('100.00')
        ->and((string) $kept->lines[0]->netAmount?->getAmount())->toBe('99.99');
});

it('uses a calculator given to create() for renders, cancellations and edits', function () {
    $spy = new class implements TotalsCalculator
    {
        public int $calls = 0;

        public function calculate(InvoiceData $invoice): InvoiceData
        {
            $this->calls++;

            return (new En16931Calculator)->calculate($invoice);
        }
    };
    $quittio = Quittio::create(calculator: $spy);
    $original = (new En16931Calculator)->calculate(Invoices::simple()->build());

    $quittio->render($original, new RenderOptions(formats: ['json']));
    $rendered = $spy->calls;
    $quittio->cancellation($original, 'CN-1')->build();
    $quittio->edit($original)->build();

    expect($quittio->calculator())->toBe($spy)
        ->and($rendered)->toBeGreaterThan(0)
        ->and($spy->calls)->toBeGreaterThan($rendered);
});

it('takes the mode as enum or in any case and names an unknown one', function () {
    expect(CalculationPolicy::fromArray(['declared_amounts' => DeclaredAmountsMode::Reject])->declaredAmounts)->toBe(DeclaredAmountsMode::Reject)
        ->and(CalculationPolicy::fromArray(['declared_amounts' => 'RECALCULATE'])->declaredAmounts)->toBe(DeclaredAmountsMode::Recalculate)
        ->and(CalculationPolicy::fromArray(['declared_amounts' => ''])->declaredAmounts)->toBe(DeclaredAmountsMode::Keep)
        ->and(fn () => CalculationPolicy::fromArray(['declared_amounts' => 'round']))->toThrow(ConfigurationException::class, 'Unknown declared amounts mode "round"')
        ->and(fn () => CalculationPolicy::fromArray(['declared_amounts' => ['keep']]))->toThrow(ConfigurationException::class, 'Unknown declared amounts mode "array"');
});
