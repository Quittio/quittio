<?php

declare(strict_types=1);

use Brick\Money\Money;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\LineCalculator;
use Quittio\Core\Corrections\Cancellation;
use Quittio\Core\Data\Extensions\ThirdPartyPayment;
use Quittio\Core\Data\Extensions\XRechnungExtensionData;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Meta;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Support\Amount;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Tests\Support\Validate;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;

/**
 * An invoice of another system that rounds per line: line 1 is 3 × 33.333 = 99.999, declared as
 * 99.99 (Quittio: 100.00), the VAT 28.49 instead of round2(149.99 × 19 %) = 28.50. Both are
 * within the tolerance of EN 16931. Read back from XML like a received invoice.
 */
function round3Foreign(string $syntax = 'cii', string $line1 = '99.99', string $vat = '28.49'): InvoiceData
{
    $calculated = (new En16931Calculator)->calculate(Invoices::base()
        ->line(fn ($l) => $l->name('Widget')->quantity(3, UnitCode::Piece)->netPrice('33.333')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('Service')->quantity(1, UnitCode::Piece)->netPrice('50.00')->vat(VatCategory::StandardRate, '19'))
        ->build());
    $eur = fn (string $amount): Money => Amount::of($amount, 'EUR');
    $net = Amount::of($line1, 'EUR')->plus($eur('50.00'));
    $gross = $net->plus($eur($vat));
    $foreign = $calculated->with([
        'lines' => [$calculated->lines[0]->with(['netAmount' => $eur($line1)]), $calculated->lines[1]],
        'vatBreakdown' => [$calculated->vatBreakdown[0]->with(['taxableAmount' => $net, 'taxAmount' => $eur($vat)])],
        'totals' => $calculated->totals?->with(['lineNetSum' => $net, 'netTotal' => $net, 'vatTotal' => $eur($vat), 'grossTotal' => $gross, 'amountDue' => $gross]),
    ]);
    $profile = ProfileRegistry::withDefaults()->get('en16931');

    return (new InvoiceReader)->read($syntax === 'cii' ? (new CiiWriter)->serialize($foreign, $profile) : (new UblWriter)->serialize($foreign, $profile))->data;
}

/**
 * @return array<string, string>
 */
function round3Amounts(InvoiceData $invoice): array
{
    $t = $invoice->totals;

    return [
        'BT-131/1' => (string) $invoice->lines[0]->netAmount?->getAmount(),
        'BT-131/2' => (string) $invoice->lines[1]->netAmount?->getAmount(),
        'BT-116' => (string) $invoice->vatBreakdown[0]->taxableAmount->getAmount(),
        'BT-117' => (string) $invoice->vatBreakdown[0]->taxAmount->getAmount(),
        'BT-106' => (string) $t?->lineNetSum->getAmount(),
        'BT-109' => (string) $t?->netTotal->getAmount(),
        'BT-110' => (string) $t?->vatTotal?->getAmount(),
        'BT-112' => (string) $t?->grossTotal->getAmount(),
        'BT-115' => (string) $t?->amountDue->getAmount(),
    ];
}

describe('declared amounts of an original', function () {
    it('reverses foreign rounding exactly in full cancellations', function (string $syntax, string $strategy, string $sign) {
        $original = round3Foreign($syntax);
        $cancellation = (new En16931Calculator)->calculate(Cancellation::of($original, 'ST-1', $strategy)->build());
        $expected = array_map(fn (string $v): string => $v === '0.00' || $sign === '' ? $v : '-'.$v, round3Amounts($original));

        expect(round3Amounts($original))->toBe(['BT-131/1' => '99.99', 'BT-131/2' => '50.00', 'BT-116' => '149.99', 'BT-117' => '28.49', 'BT-106' => '149.99', 'BT-109' => '149.99', 'BT-110' => '28.49', 'BT-112' => '178.48', 'BT-115' => '178.48'])
            ->and(round3Amounts($cancellation))->toBe($expected)
            ->and($cancellation->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED))->toBeFalse()
            ->and(Validate::data($cancellation, calculate: false)->errors())->toBe([]);
    })->with(['cii', 'ubl'])->with([['credit_note', ''], ['negative_invoice', '-']]);

    it('keeps the declared amounts of unchanged data started with from() and recalculates changed data', function () {
        $original = round3Foreign();

        $unchanged = (new En16931Calculator)->calculate(InvoiceBuilder::from($original)->number('X-2')->build());
        $changed = (new En16931Calculator)->calculate(InvoiceBuilder::from($original)->line(fn ($l) => $l->name('Extra')->quantity(1)->netPrice('10.00')->vat(VatCategory::StandardRate, '19'))->build());

        expect(round3Amounts($unchanged))->toBe(round3Amounts($original))
            ->and($changed->lines[0]->netAmount?->getAmount()->toString())->toBe('100.00')
            ->and($changed->vatBreakdown[0]->taxAmount->getAmount()->toString())->toBe('30.40')
            ->and($changed->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS))->toBeFalse();
    });

    it('keeps the declared amounts of whole lines in partial cancellations and recalculates their VAT', function () {
        $cancellation = (new En16931Calculator)->calculate(Cancellation::of(round3Foreign(), 'ST-2', lines: [['line' => '1'], ['line' => '2', 'quantity' => '1']])->build());

        expect($cancellation->lines[0]->netAmount?->getAmount()->toString())->toBe('99.99')
            ->and($cancellation->vatBreakdown[0]->taxableAmount->getAmount()->toString())->toBe('149.99')
            ->and($cancellation->vatBreakdown[0]->taxAmount->getAmount()->toString())->toBe('28.50')
            ->and(Validate::codes(Validate::data($cancellation, calculate: false)))->not->toContain('QUITTIO-DECLARED-AMOUNTS');
    });

    it('recalculates declared amounts beyond the tolerance and warns', function (string $line1, string $vat) {
        $cancellation = (new En16931Calculator)->calculate(Cancellation::of(round3Foreign('cii', $line1, $vat), 'ST-3')->build());
        $result = Validate::data($cancellation, calculate: false);

        expect($cancellation->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED))->toBeTrue()
            ->and(Validate::codes($result))->toContain('QUITTIO-DECLARED-AMOUNTS')
            ->and($result->errors())->toBe([]);
    })->with([
        'line amount 2.00 off' => ['98.00', '28.11'],
        'VAT 1.00 off' => ['99.99', '29.50'],
    ]);
});

describe('implied exchange rate', function () {
    it('drops a rate implied by from() once lines change and requires an explicit rate', function () {
        $original = (new En16931Calculator)->calculate(Invoices::base()->currency('USD')->taxCurrency('EUR', '0.917')
            ->line(fn ($l) => $l->name('Pen')->quantity(1)->netPrice('0.05')->vat(VatCategory::StandardRate, '19'))->build());
        $read = (new InvoiceReader)->read((new CiiWriter)->serialize($original, ProfileRegistry::withDefaults()->get('en16931')))->data;
        $add = fn (InvoiceBuilder $b): InvoiceBuilder => $b->line(fn ($l) => $l->name('Desk')->quantity(1)->netPrice('1000.00')->vat(VatCategory::StandardRate, '19'));

        $unchanged = (new En16931Calculator)->calculate(InvoiceBuilder::from($read)->build());
        $withoutRate = $add(InvoiceBuilder::from($read))->build();
        $withRate = (new En16931Calculator)->calculate($add(InvoiceBuilder::from($read))->exchangeRate('0.917')->build());

        expect((string) $unchanged->totals?->vatTotalInTaxCurrency?->getAmount())->toBe('0.01')
            ->and($withoutRate->meta->exchangeRate)->toBeNull()
            ->and(Validate::codes(Validate::data($withoutRate)))->toContain('BR-53')
            ->and((string) $withRate->totals?->vatTotalInTaxCurrency?->getAmount())->toBe('174.24');
    });

    it('keeps the rate of the original in cancellations', function () {
        $original = (new En16931Calculator)->calculate(Invoices::simple()->currency('USD')->taxCurrency('EUR', '0.857143')->build());
        $read = (new InvoiceReader)->read((new UblWriter)->serialize($original, ProfileRegistry::withDefaults()->get('en16931')))->data;

        $partial = (new En16931Calculator)->calculate(Cancellation::of($read, 'ST-4', lines: [['line' => '1', 'quantity' => '5']])->build());

        expect($partial->meta->exchangeRate)->not->toBeNull()
            ->and((string) $partial->totals?->vatTotalInTaxCurrency?->getAmount())->toBe('81.43');
    });
});

describe('discounts split over VAT groups', function () {
    $mixed = fn () => Invoices::base()
        ->line(fn ($l) => $l->name('Book')->quantity(1)->netPrice('100.00')->vat(VatCategory::StandardRate, '7'))
        ->line(fn ($l) => $l->name('Pen')->quantity(1)->netPrice('300.00')->vat(VatCategory::StandardRate, '19'));

    it('splits a discount amount again when lines change after from()', function () use ($mixed) {
        $original = (new En16931Calculator)->calculate($mixed()->discountAmount('40.00', 'Rabatt')->build());
        $changed = (new En16931Calculator)->calculate(InvoiceBuilder::from($original)->removeLine('2')->line(fn ($l) => $l->name('Lamp')->quantity(1)->netPrice('100.00')->vat(VatCategory::StandardRate, '19'))->build());
        $parts = array_map(fn ($a): string => $a->vatRate?->toString().':'.$a->amount?->getAmount(), $changed->allowances);

        expect(array_map(fn ($a): string => $a->vatRate?->toString().':'.$a->amount?->getAmount(), $original->allowances))->toBe(['7:10.00', '19:30.00'])
            ->and($parts)->toBe(['7:20.00', '19:20.00'])
            ->and($changed->totals?->allowanceSum?->getAmount()->toString())->toBe('40.00');
    });

    it('splits a percentage discount again when lines change after from()', function () {
        // 1 % of 3.00 is 0.03, split 0.02 / 0.01: the second part has no percentage (0.01 ≠ 1 % of 1.50).
        $original = (new En16931Calculator)->calculate(Invoices::base()
            ->line(fn ($l) => $l->name('Book')->quantity(1)->netPrice('1.50')->vat(VatCategory::StandardRate, '7'))
            ->line(fn ($l) => $l->name('Pen')->quantity(1)->netPrice('1.50')->vat(VatCategory::StandardRate, '19'))
            ->discountPercent('1', 'Rabatt')->build());
        $changed = (new En16931Calculator)->calculate(InvoiceBuilder::from($original)->updateLine('2', fn ($l) => $l->withNetPrice('3.00'))->build());

        expect(array_map(fn ($a): string => $a->amount?->getAmount().'/'.($a->percent ?? '-'), $original->allowances))->toBe(['0.02/1', '0.01/-'])
            ->and(array_map(fn ($a): string => (string) $a->amount?->getAmount(), $changed->allowances))->toBe(['0.02', '0.03'])
            ->and($changed->totals?->allowanceSum?->getAmount()->toString())->toBe('0.05');
    });

    it('lets percentage allowances of every group follow their group', function () use ($mixed) {
        $original = (new En16931Calculator)->calculate($mixed()->discountPercent('3', 'Rabatt')->build());
        $changed = (new En16931Calculator)->calculate(InvoiceBuilder::from($original)->updateLine('2', fn ($l) => $l->withNetPrice('200.00'))->build());

        expect(array_map(fn ($a): string => (string) $a->amount?->getAmount(), $changed->allowances))->toBe(['3.00', '6.00']);
    });

    it('keeps allowances of single VAT groups and unchanged splits', function () use ($mixed) {
        $original = (new En16931Calculator)->calculate($mixed()->allowance(VatCategory::StandardRate, '19', amount: '25.00', reason: 'Bonus')->discountAmount('40.00', 'Rabatt')->build());
        $unchanged = (new En16931Calculator)->calculate(InvoiceBuilder::from($original)->build());
        $changed = (new En16931Calculator)->calculate(InvoiceBuilder::from($original)->removeLine('1')->build());

        expect(array_map(fn ($a): string => (string) $a->amount?->getAmount(), $unchanged->allowances))->toBe(['25.00', '10.00', '30.00'])
            ->and(array_map(fn ($a): string => $a->reason.':'.$a->amount?->getAmount(), $changed->allowances))->toBe(['Bonus:25.00', 'Rabatt:40.00']);
    });
});

describe('third party payments (BG-DEX-09)', function () {
    $invoice = fn (string $type = '380') => (new En16931Calculator)->calculate(Invoices::simple()->type($type)
        ->xrechnungExtension(new XRechnungExtensionData([new ThirdPartyPayment('Porto', Amount::of('4.90', 'EUR'), 'Briefporto')]))->build());

    it('rejects them in CII, in credit notes and outside XRechnung', function (string $type, string $profile, ?Syntax $syntax) use ($invoice) {
        $result = Validate::data($invoice($type), $profile, calculate: false, options: $syntax === null ? [] : ['syntax' => $syntax]);

        expect(Validate::codes($result))->toContain('QUITTIO-THIRD-PARTY-PAYMENTS');
    })->with([
        'EN 16931 UBL' => ['380', 'en16931', Syntax::Ubl],
        'Factur-X EXTENDED' => ['380', 'extended', null],
        'credit note' => ['381', 'en16931', Syntax::Ubl],
    ]);
});

describe('round 3b: declared amounts that cannot be kept', function () {
    it('recalculates a declared VAT amount of a category without VAT instead of keeping it (BR-Z-09)', function (string $strategy) {
        $calculated = (new En16931Calculator)->calculate(Invoices::base()
            ->line(fn ($l) => $l->name('Widget')->quantity(1)->netPrice('100.00')->vat(VatCategory::StandardRate, '19'))
            ->line(fn ($l) => $l->name('Export')->quantity(1)->netPrice('50.00')->vat(VatCategory::ZeroRated, '0'))
            ->build());
        $eur = fn (string $amount): Money => Amount::of($amount, 'EUR');
        // Another system declares 0.30 VAT for the zero rated group; BR-CO-17 alone would tolerate it.
        $foreign = $calculated->with([
            'vatBreakdown' => [$calculated->vatBreakdown[0], $calculated->vatBreakdown[1]->with(['taxAmount' => $eur('0.30')])],
            'totals' => $calculated->totals?->with(['vatTotal' => $eur('19.30'), 'grossTotal' => $eur('169.30'), 'amountDue' => $eur('169.30')]),
        ]);
        $read = (new InvoiceReader)->read((new CiiWriter)->serialize($foreign, ProfileRegistry::withDefaults()->get('en16931')))->data;

        $cancellation = (new En16931Calculator)->calculate(Cancellation::of($read, 'ST-5', $strategy)->build());
        $result = Validate::data($cancellation, calculate: false);

        expect((string) $read->vatBreakdown[1]->taxAmount->getAmount())->toBe('0.30')
            ->and((string) $cancellation->vatBreakdown[1]->taxAmount->getAmount())->toBe('0.00')
            ->and($cancellation->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED))->toBeTrue()
            ->and(Validate::codes($result))->toContain('QUITTIO-DECLARED-AMOUNTS')
            ->and($result->errors())->toBe([]);
    })->with(['credit_note', 'negative_invoice']);

    it('keeps QUITTIO-DECLARED-AMOUNTS when calculated data is calculated again or continued with from()', function () {
        $calculator = new En16931Calculator;
        $once = $calculator->calculate(Cancellation::of(round3Foreign('cii', '98.00', '28.11'), 'ST-8')->build());
        $twice = $calculator->calculate($once);
        $continued = $calculator->calculate(InvoiceBuilder::from($once)->note('Korrektur')->build());
        $changed = $calculator->calculate(InvoiceBuilder::from($once)->removeLine('2')->build());

        expect($once->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED))->toBeTrue()
            ->and(Validate::codes(Validate::data($twice, calculate: false)))->toContain('QUITTIO-DECLARED-AMOUNTS')
            ->and(Validate::codes(Validate::data($continued, calculate: false)))->toContain('QUITTIO-DECLARED-AMOUNTS')
            ->and($changed->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED))->toBeFalse();
    });

    $usd = function (): InvoiceData {
        $original = (new En16931Calculator)->calculate(Invoices::base()->currency('USD')->taxCurrency('EUR', '0.917')
            ->line(fn ($l) => $l->name('Pen')->quantity(1)->netPrice('0.05')->vat(VatCategory::StandardRate, '19'))->build());

        return (new InvoiceReader)->read((new CiiWriter)->serialize($original, ProfileRegistry::withDefaults()->get('en16931')))->data;
    };

    it('drops a rate implied by from() when the VAT accounting currency changes', function () use ($usd) {
        $chf = InvoiceBuilder::from($usd())->taxCurrency('CHF')->build();
        $withRate = (new En16931Calculator)->calculate(InvoiceBuilder::from($usd())->taxCurrency('CHF', '0.85')->build());

        expect($chf->meta->exchangeRate)->toBeNull()
            ->and(Validate::codes(Validate::data($chf)))->toContain('BR-53')
            ->and((string) $withRate->totals?->vatTotalInTaxCurrency?->getAmount())->toBe('0.01')
            ->and($withRate->totals?->vatTotalInTaxCurrency?->getCurrency()->getCurrencyCode())->toBe('CHF');
    });

    it('drops the implied rate of a cancellation once lines are added', function () use ($usd) {
        $read = $usd();
        $full = (new En16931Calculator)->calculate(Cancellation::of($read, 'ST-6')->build());
        $added = Cancellation::of($read, 'ST-7')->line(fn ($l) => $l->name('Desk')->quantity(1)->netPrice('1000.00')->vat(VatCategory::StandardRate, '19'))->build();

        expect((string) $full->totals?->vatTotalInTaxCurrency?->getAmount())->toBe('0.01')
            ->and($added->meta->exchangeRate)->toBeNull()
            ->and(Validate::codes(Validate::data($added)))->toContain('BR-53');
    });
});

describe('round 3b: percentage discounts split over VAT groups', function () {
    // 10 % of 100.00 (7 %) and 300.00 (19 %): both parts keep their percentage.
    $original = fn () => (new En16931Calculator)->calculate(Invoices::base()
        ->line(fn ($l) => $l->name('Book')->quantity(1)->netPrice('100.00')->vat(VatCategory::StandardRate, '7'))
        ->line(fn ($l) => $l->name('Pen')->quantity(1)->netPrice('300.00')->vat(VatCategory::StandardRate, '19'))
        ->discountPercent('10', 'Rabatt')->build());
    $parts = fn (InvoiceData $i): array => array_map(fn ($a): string => $a->vatCategory->value.$a->vatRate?->toString().':'.$a->amount?->getAmount(), $i->allowances);

    it('gives the discount to lines of a new VAT group', function () use ($original, $parts) {
        $changed = (new En16931Calculator)->calculate(InvoiceBuilder::from($original())
            ->line(fn ($l) => $l->name('Export')->quantity(1)->netPrice('200.00')->vat(VatCategory::ZeroRated, '0'))->build());

        expect($parts($original()))->toBe(['S7:10.00', 'S19:30.00'])
            ->and($parts($changed))->toBe(['S7:10.00', 'S19:30.00', 'Z0:20.00'])
            ->and((string) $changed->totals?->amountDue->getAmount())->toBe('597.60');
    });

    it('keeps no allowance of 0.00 for a VAT group without lines', function () use ($original, $parts) {
        $changed = (new En16931Calculator)->calculate(InvoiceBuilder::from($original())->removeLine('1')->build());

        expect($parts($changed))->toBe(['S19:30.00'])
            ->and(array_map(fn ($b): string => $b->category->value.$b->rate?->toString(), $changed->vatBreakdown))->toBe(['S19']);
    });
});

describe('round 3b: declared amounts only where needed and never stale', function () {
    it('never flags data whose amounts the recalculation reproduces', function () {
        $quittio = (new En16931Calculator)->calculate(Invoices::simple()->build());

        expect(InvoiceBuilder::from($quittio)->build()->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS))->toBeFalse()
            ->and(Cancellation::of($quittio, 'ST-9')->build()->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS))->toBeFalse()
            ->and(InvoiceBuilder::from(round3Foreign())->build()->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS))->toBeTrue();
    });

    it('recalculates amounts changed outside the builder (with(), stored JSON)', function (string $source) {
        $calculator = new En16931Calculator;
        $data = match ($source) {
            'quittio' => $calculator->calculate(Invoices::simple()->build()),
            'foreign draft' => InvoiceBuilder::from(round3Foreign())->build(),
            default => $calculator->calculate(Cancellation::of(round3Foreign(), 'ST-10')->build()),
        };
        $stored = InvoiceData::fromJson($data->toJson());
        $line = $stored->lines[0];
        $edited = $stored->with(['lines' => [$line->with(['price' => $line->price->with(['netPrice' => $line->price->netPrice?->plus('0.01')])]), ...array_slice($stored->lines, 1)]]);
        $expected = (new LineCalculator)->calculate($edited->lines[0]->with(['netAmount' => null]), 'EUR')->netAmount;

        $direct = $calculator->calculate($edited);
        $rebuilt = $calculator->calculate(InvoiceBuilder::from($edited)->build());
        $cancelled = $calculator->calculate(Cancellation::of($edited, 'ST-11')->build());

        expect((string) $direct->lines[0]->netAmount?->getAmount())->toBe((string) $expected?->getAmount())
            ->and((string) $rebuilt->lines[0]->netAmount?->getAmount())->toBe((string) $expected?->getAmount())
            ->and((string) $cancelled->lines[0]->netAmount?->getAmount())->toBe((string) $expected?->getAmount())
            ->and($direct->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS))->toBeFalse()
            ->and(Validate::codes(Validate::data($direct, calculate: false)))->not->toContain('QUITTIO-LINE-ROUNDING');
    })->with(['quittio', 'foreign draft', 'foreign cancellation']);

    it('keeps a declared line net amount only within 0.02 of the unrounded line formula and warns', function (string $line1, bool $kept) {
        // 3 × 33.333 = 99.999
        $cancellation = (new En16931Calculator)->calculate(Cancellation::of(round3Foreign('cii', $line1, '28.49'), 'ST-12')->build());
        $codes = Validate::codes(Validate::data($cancellation, calculate: false));

        expect((string) $cancellation->lines[0]->netAmount?->getAmount())->toBe($kept ? $line1 : '100.00')
            ->and($cancellation->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS_RECALCULATED))->toBe(! $kept)
            ->and(in_array('QUITTIO-LINE-ROUNDING', $codes, true))->toBe($kept)
            ->and(in_array('QUITTIO-DECLARED-AMOUNTS', $codes, true))->toBe(! $kept);
    })->with([
        '0.019 off' => ['99.98', true],
        '0.029 off' => ['99.97', false],
    ]);

    it('checks PEPPOL-EN16931-R120 with the official slack of 0.02 against the unrounded product', function (string $netAmount, bool $error) {
        $invoice = (new En16931Calculator)->calculate(Invoices::base()
            ->line(fn ($l) => $l->name('Widget')->quantity(3, UnitCode::Piece)->netPrice('33.333')->vat(VatCategory::StandardRate, '19'))->build());
        $invoice = $invoice->with(['lines' => [$invoice->lines[0]->with(['netAmount' => Amount::of($netAmount, 'EUR')])]]);

        expect(in_array('PEPPOL-EN16931-R120', Validate::codes(Validate::data($invoice, 'peppol', calculate: false)), true))->toBe($error);
    })->with([
        'calculated' => ['100.00', false],
        'foreign rounding' => ['99.99', false],
        'at the slack' => ['99.98', false],
        'beyond the slack' => ['99.97', true],
        'above' => ['100.03', true],
    ]);
});

it('recalculates read data that was edited before from()', function (string $syntax) {
    $read = round3Foreign($syntax);
    // Price 33.333 → 33.334 directly on the read data (e.g. a stored snapshot edited by hand): 3 × 33.334
    // = 100.002 is within 0.02 of the declared 99.99, so only the fingerprint reveals the edit.
    $line = $read->lines[0];
    $edited = $read->with(['lines' => [$line->with(['price' => $line->price->with(['netPrice' => Brick\Math\BigDecimal::of('33.334')])]), $read->lines[1]]]);
    $result = (new En16931Calculator)->calculate(InvoiceBuilder::from($edited)->build());

    expect((string) $result->lines[0]->netAmount?->getAmount())->toBe('100.00')
        ->and($result->meta->hasFlag(Meta::FLAG_DECLARED_AMOUNTS))->toBeFalse();
})->with(['cii', 'ubl']);
