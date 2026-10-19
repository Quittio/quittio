<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\View\InvoiceViewModel;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Tax\VatPreset;

it('keeps the margin scheme note next to a § 4 exemption in the one E breakdown', function (string $format) {
    $builder = Scenarios::base()->buyerReference('04011000-12345-03')
        ->line(fn ($l) => $l->name('Heilbehandlung')->quantity(1, UnitCode::Piece)->netPrice('90.00')->vat(VatPreset::exempt('§ 4 Nr. 14 UStG')))
        ->line(fn ($l) => $l->name('Gebrauchtes Gerät')->quantity(1, UnitCode::Piece)->netPrice('500.00')->vat(VatPreset::MarginSecondHand));

    $result = Quittio::create([], [new CountryDE])->make($builder)->format($format)->render();
    $breakdown = $result->data()->vatBreakdown;

    expect($breakdown)->toHaveCount(1)
        ->and($breakdown[0]->exemptionReason)->toContain('§ 4 Nr. 14 UStG')
        ->and($breakdown[0]->exemptionReason)->toContain('Gebrauchtgegenstände/Sonderregelung')
        ->and($breakdown[0]->exemptionReasonCode)->toBeNull()
        ->and($result->as($format)->xml())->toContain('Gebrauchtgegenstände/Sonderregelung')
        ->and($result->validation()->isValid())->toBeTrue()
        ->and($result->validation()->has('QUITTIO-EXEMPTION-CONFLICT'))->toBeTrue();
})->with(['xrechnung-cii', 'xrechnung-ubl']);

function marginView(InvoiceBuilder $builder): InvoiceViewModel
{
    $invoice = (new En16931Calculator)->calculate($builder->build());

    return (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de', country: new CountryDE));
}

/**
 * @return list<array{string, ?string}>
 */
function marginRows(InvoiceViewModel $view): array
{
    $rows = [];
    foreach ($view->vatRows as $row) {
        $rows[] = [(string) $row->label, $row->amount === null ? null : (string) $row->amount];
    }

    return $rows;
}

it('shows the margin scheme and the exemption of one E group in rows of their own in the PDF', function () {
    $view = marginView(Scenarios::base()
        ->line(fn ($l) => $l->name('Heilbehandlung')->quantity(1, UnitCode::Piece)->netPrice('90.00')->vat(VatPreset::exempt('§ 4 Nr. 14 UStG')))
        ->line(fn ($l) => $l->name('Gebrauchtes Gerät')->quantity(1, UnitCode::Piece)->netPrice('500.00')->vat(VatPreset::MarginSecondHand)));
    $lines = iterator_to_array($view->lines);

    expect(marginRows($view))->toBe([
        ["Sonderregelung, kein gesonderter Steuerausweis: 500,00\u{00A0}€", null],
        ["Umsatzsteuerfrei (Steuerbefreit) auf 90,00\u{00A0}€", "0,00\u{00A0}€"],
    ])
        // The margin scheme line shows no rate, the exempt line its 0 %.
        ->and($lines[0]->vatRate === null ? null : (string) $lines[0]->vatRate)->toBe("0\u{00A0}%")
        ->and($lines[1]->vatRate)->toBeNull();
});

it('shows one row without rate and VAT when the parts of the E group cannot be told apart', function () {
    $view = marginView(Scenarios::base()
        ->line(fn ($l) => $l->name('Heilbehandlung')->quantity(1, UnitCode::Piece)->netPrice('90.00')->vat(VatPreset::exempt('§ 4 Nr. 14 UStG')))
        ->line(fn ($l) => $l->name('Gebrauchtes Gerät')->quantity(1, UnitCode::Piece)->netPrice('500.00')->vat(VatPreset::MarginSecondHand))
        ->charge(VatCategory::Exempt, '0', amount: '10.00', reason: 'Versand'));

    expect(marginRows($view))->toBe([["Steuerfrei bzw. Sonderregelung, kein gesonderter Steuerausweis: 600,00\u{00A0}€", null]]);
});

it('treats an E group of different special schemes as special scheme', function () {
    $view = marginView(Scenarios::base()
        ->line(fn ($l) => $l->name('Gebrauchtes Gerät')->quantity(1, UnitCode::Piece)->netPrice('500.00')->vat(VatPreset::MarginSecondHand))
        ->line(fn ($l) => $l->name('Gemälde')->quantity(1, UnitCode::Piece)->netPrice('800.00')->vat(VatPreset::MarginArt)));

    expect(marginRows($view))->toBe([["Sonderregelung, kein gesonderter Steuerausweis: 1.300,00\u{00A0}€", null]]);
});

it('keeps the mandatory output of reverse charge rows after a split E group', function () {
    $builder = Scenarios::base()
        ->buyer(fn ($b) => $b->name('Client SARL')->vatId('FR12345678901')->address('1 Rue', '75002', 'Paris', 'FR'))
        ->line(fn ($l) => $l->name('Heilbehandlung')->quantity(1, UnitCode::Piece)->netPrice('90.00')->vat(VatPreset::exempt('§ 4 Nr. 14 UStG')))
        ->line(fn ($l) => $l->name('Gebrauchtes Gerät')->quantity(1, UnitCode::Piece)->netPrice('500.00')->vat(VatPreset::MarginSecondHand))
        ->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::ReverseChargeEu));
    $invoice = (new En16931Calculator)->calculate($builder->build());
    $rowIndexes = ViewModelFactory::vatRowIndexes($invoice);
    $ae = array_key_first(array_filter($invoice->vatBreakdown, static fn ($row): bool => $row->category === VatCategory::ReverseCharge));

    expect(count(marginRows(marginView($builder))))->toBe(count($invoice->vatBreakdown) + 1)
        ->and(marginRows(marginView($builder))[$rowIndexes[$ae]][0])->toContain('Steuerschuldnerschaft')
        ->and(Quittio::create(['mandatory_fields' => 'exception'], [new CountryDE])->make($builder)->format('pdf')->validate(false)->render()->pdf())->toStartWith('%PDF');
});
