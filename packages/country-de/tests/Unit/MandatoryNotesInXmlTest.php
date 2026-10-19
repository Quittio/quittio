<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Corrections\PriceReductionReason;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;
use Quittio\Country\DE\Builder\GermanInvoice;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Tax\VatPreset;

/*
 * Every mandatory statement belongs to the structured part of an e-invoice, not only to the PDF
 * (BMF letter of 15 October 2025, marginal no. 35): each one must appear in the XML of both
 * syntaxes, as note (BT-22), exemption reason (BT-120) or payment terms (BT-20).
 */
it('writes the mandatory statement into the XML', function (Closure $build, string $statement) {
    $quittio = Quittio::create([], [new CountryDE]);
    $builder = $build($quittio);

    foreach (['cii', 'ubl'] as $format) {
        $xml = (string) $quittio->make($builder)->format($format)->validate(false)->render()->xml();
        expect(html_entity_decode($xml, ENT_QUOTES | ENT_XML1))->toContain($statement);
    }
})->with([
    'self-billing' => [fn () => Scenarios::make('simple')->de()->selfBilled(), 'Gutschrift'],
    'retention notice' => [fn () => Scenarios::make('simple')->de()->propertyRelatedService(), '§ 14b Abs. 1 Satz 5 UStG'],
    'reverse charge' => [fn () => Scenarios::base()->buyer(fn ($b) => $b->name('Client SARL')->vatId('FR12345678901')->address('1 Rue', '75002', 'Paris', 'FR'))
        ->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::ReverseChargeEu)), 'Steuerschuldnerschaft des Leistungsempfängers'],
    'exemption' => [fn () => Scenarios::base()->line(fn ($l) => $l->name('Behandlung')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::exempt('§ 4 Nr. 14 UStG'))), 'Steuerfrei nach § 4 Nr. 14 UStG'],
    'margin scheme' => [fn () => Scenarios::base()->line(fn ($l) => $l->name('Gebrauchtwagen')->quantity(1, UnitCode::Piece)->netPrice('8000.00')->vat(VatPreset::MarginSecondHand)), 'Gebrauchtgegenstände/Sonderregelung'],
    'photovoltaic' => [fn () => Scenarios::base()->line(fn ($l) => $l->name('PV-Anlage')->quantity(1, UnitCode::Piece)->netPrice('12000.00')->vat(VatPreset::ZeroRatedPv)), 'Steuersatz 0 % nach § 12 Abs. 3 UStG.'],
    'small business' => [fn () => Scenarios::base()->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::SmallBusiness)), '§ 19 Abs. 1 UStG'],
    'cash discount' => [fn () => Scenarios::make('simple'), '2 % Skonto bei Zahlung innerhalb von 7 Tagen'],
    'discount agreement' => [fn () => Scenarios::make('simple')->de()->discountAgreement('der Bonusvereinbarung vom 01.01.2026'), 'Bonusvereinbarung vom 01.01.2026'],
    'prepayment' => [fn () => Scenarios::make('simple')->de()->advance('2027-03', receivedOn: '2026-09-28'), 'Die Anzahlung wurde am 28.09.2026 vereinnahmt.'],
    'advance deduction' => [fn () => Scenarios::make('simple')->de()->deductAdvance((new En16931Calculator)->calculate(Scenarios::make('simple')->number('AB-1')->type('386')->build()), received: GermanInvoice::RECEIVED_IN_FULL), 'Abzüglich Anzahlung gemäß Rechnung AB-1'],
    'consumer default' => [fn () => Scenarios::make('simple')->buyer(fn ($b) => $b->vatId(null)->consumer()), '§ 286 Abs. 3 BGB'],
    'correction' => [fn (Quittio $q) => $q->correction((new En16931Calculator)->calculate(Scenarios::make('simple')->build()), 'RE-2'), 'Diese Rechnung berichtigt und ersetzt die Rechnung RE-2026-0042'],
    'price reduction' => [fn (Quittio $q) => $q->priceReduction((new En16931Calculator)->calculate(Scenarios::make('simple')->build()), 'EM-1', PriceReductionReason::CashDiscount, percent: '2'), 'Minderung des Entgelts zur Rechnung RE-2026-0042'],
    'cancellation' => [fn (Quittio $q) => $q->cancellation((new En16931Calculator)->calculate(Scenarios::make('simple')->build()), 'ST-1'), 'Stornierung der Rechnung RE-2026-0042'],
]);
