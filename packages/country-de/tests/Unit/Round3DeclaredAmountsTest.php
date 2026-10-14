<?php

declare(strict_types=1);

use Brick\Money\Money;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Corrections\Cancellation;
use Quittio\Core\Data\Extensions\ThirdPartyPayment;
use Quittio\Core\Data\Extensions\XRechnungExtensionData;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Quittio;
use Quittio\Core\Support\Amount;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Official;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Tests\Support\German;

/**
 * XRechnung of another system with per line rounding (99.99 instead of 100.00, VAT 28.49 instead of 28.50).
 */
function round3ForeignXRechnung(string $syntax): InvoiceData
{
    $calculated = (new En16931Calculator)->calculate(Scenarios::base()->buyerReference('04011000-12345-03')
        ->line(fn ($l) => $l->name('Widget')->quantity(3, UnitCode::Piece)->netPrice('33.333')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('Service')->quantity(1, UnitCode::Piece)->netPrice('50.00')->vat(VatCategory::StandardRate, '19'))
        ->build());
    $eur = fn (string $amount): Money => Amount::of($amount, 'EUR');
    $foreign = $calculated->with([
        'lines' => [$calculated->lines[0]->with(['netAmount' => $eur('99.99')]), $calculated->lines[1]],
        'vatBreakdown' => [$calculated->vatBreakdown[0]->with(['taxableAmount' => $eur('149.99'), 'taxAmount' => $eur('28.49')])],
        'totals' => $calculated->totals?->with(['lineNetSum' => $eur('149.99'), 'netTotal' => $eur('149.99'), 'vatTotal' => $eur('28.49'), 'grossTotal' => $eur('178.48'), 'amountDue' => $eur('178.48')]),
    ]);
    $profile = German::profiles()->get('xrechnung');

    return German::reader()->read($syntax === 'cii' ? (new CiiWriter)->serialize($foreign, $profile) : (new UblWriter)->serialize($foreign, $profile))->data;
}

it('cancels an XRechnung with foreign rounding exactly and passes KoSIT', function (string $strategy, string $format) {
    $original = round3ForeignXRechnung(str_ends_with($format, 'ubl') ? 'ubl' : 'cii');
    $result = Quittio::create([], [new CountryDE])->withValidator(Official::kosit())
        ->make(Cancellation::of($original, 'ST-2026-0100', $strategy))->format($format)->render();
    $totals = $result->data()->totals;
    $sign = $strategy === 'negative_invoice' ? '-' : '';

    expect($result->validation()->levels)->toContain(ValidationLevel::L5)
        ->and($result->validation()->errors())->toBe([])
        ->and((string) $totals?->vatTotal?->getAmount())->toBe($sign.'28.49')
        ->and((string) $totals?->amountDue->getAmount())->toBe($sign.'178.48')
        ->and((string) $result->data()->lines[0]->netAmount?->getAmount())->toBe($sign.'99.99');
})->with(['credit_note', 'negative_invoice'])->with(['xrechnung-cii', 'xrechnung-ubl'])
    ->group('official')->skip(fn () => ! Official::available(), 'KoSIT validator is not available.');

it('allows third party payments only in XRechnung UBL invoices', function (string $profile, ?Syntax $syntax, string $type, bool $error) {
    $invoice = (new En16931Calculator)->calculate(Scenarios::make('simple')->buyerReference('04011000-12345-03')->type($type)
        ->xrechnungExtension(new XRechnungExtensionData([new ThirdPartyPayment('Porto', Amount::of('4.90', 'EUR'), 'Briefporto')]))->build());
    $context = new ValidationContext(German::profiles()->get($profile), [new CountryDE], 'en', options: $syntax === null ? [] : ['syntax' => $syntax]);
    $codes = array_map(fn ($m) => $m->code, (new PhpRulesDriver)->validate(new ValidationSubject($invoice, $context))->errors());

    expect(in_array('QUITTIO-THIRD-PARTY-PAYMENTS', $codes, true))->toBe($error);
})->with([
    'XRechnung UBL invoice' => ['xrechnung', Syntax::Ubl, '380', false],
    'XRechnung extension UBL invoice' => ['xrechnung-extension', null, '380', false],
    'XRechnung CII invoice' => ['xrechnung', Syntax::Cii, '380', true],
    'XRechnung UBL credit note' => ['xrechnung', Syntax::Ubl, '381', true],
    'ZUGFeRD EXTENDED' => ['extended', null, '380', true],
]);
