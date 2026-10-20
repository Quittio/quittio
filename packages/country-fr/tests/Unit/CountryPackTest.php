<?php

declare(strict_types=1);

use Brick\Money\Money;
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Dunning\DefaultInterest;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Formats\DefaultFormatResolver;
use Quittio\Core\Quittio;
use Quittio\Core\Support\Date;
use Quittio\Core\Tests\Support\Official;
use Quittio\Country\FR\CountryFR;
use Quittio\Country\FR\Tax\VatPreset;
use Quittio\Country\FR\Tests\Support\French;
use Quittio\Country\FR\Texts\LegalTexts;

it('has the French rates and mentions', function () {
    expect(array_map(fn (VatPreset $p) => (string) $p->rate(), [VatPreset::Standard, VatPreset::Intermediate, VatPreset::Reduced, VatPreset::Particular]))->toBe(['20', '10', '5.5', '2.1'])
        ->and(VatPreset::SmallBusiness->exemptionReason())->toBe('TVA non applicable, art. 293 B du CGI')
        ->and(VatPreset::ReverseChargeEu->exemptionReason())->toBe('Autoliquidation')
        ->and(VatPreset::exempt('article 261-4-4° du CGI')->exemptionReason('en'))->toBe('VAT exempt, article 261-4-4° du CGI');
});

it('adds the late payment mention to invoices between businesses', function () {
    $completed = (new CountryFR)->complete(French::invoice()->build(), []);
    $consumer = (new CountryFR)->complete(French::invoice()->buyer(fn ($b) => $b->consumer())->build(), []);

    expect($completed->notes[array_key_last($completed->notes)]->content)->toBe(LegalTexts::text(LegalTexts::LATE_PAYMENT))
        ->and($consumer->notes)->toBe([])
        ->and((new CountryFR)->complete($completed, [])->notes)->toHaveCount(count($completed->notes))
        ->and((new CountryFR)->complete(French::invoice()->build(), ['payment_mentions' => false])->notes)->toBe([]);
});

it('states the option to pay VAT on debits with its flag', function () {
    $completed = (new CountryFR)->complete(French::invoice()->flag(CountryFR::FLAG_VAT_ON_DEBITS)->build(), ['payment_mentions' => false]);

    expect(array_map(fn ($n) => $n->content, $completed->notes))->toBe(['Option pour le paiement de la taxe d\'après les débits']);
});

it('cites the CIBS instead of the CGI on invoices issued from 1 January 2027', function () {
    $reasons = fn (string $issueDate) => array_map(fn ($row) => $row->exemptionReason, French::calculated(French::base()->issueDate($issueDate)
        ->line(fn ($l) => $l->name('Machine')->quantity(1, UnitCode::Piece)->netPrice('1000.00')->vat(VatPreset::Export))
        ->line(fn ($l) => $l->name('Pièces')->quantity(1, UnitCode::Piece)->netPrice('200.00')->vat(VatPreset::IntraCommunitySupply))
        ->line(fn ($l) => $l->name('Soins')->quantity(1, UnitCode::Piece)->netPrice('50.00')->vat(VatPreset::exempt('article 261-4-1° du CGI'))), ['payment_mentions' => false])->vatBreakdown);

    expect($reasons('2026-12-31'))->toEqualCanonicalizing(['Exonération de TVA, article 262 I du CGI', 'Exonération de TVA, article 262 ter I du CGI', 'Exonération de TVA, article 261-4-1° du CGI'])
        ->and($reasons('2027-01-04'))->toEqualCanonicalizing(['Exonération de TVA, article L. 213-12, 2° du CIBS', 'Exonération de TVA, article L. 213-12, 1° du CIBS', 'Exonération de TVA, article 261-4-1° du CGI'])
        ->and(VatPreset::SmallBusiness->exemptionReasonOn(Date::of(2027, 1, 1)))->toBe('TVA non applicable, art. L. 233-3 du CIBS')
        ->and(VatPreset::SmallBusiness->exemptionReasonOn(Date::of(2026, 12, 31), 'en'))->toBe('VAT not applicable, art. 293 B of the French Tax Code (CGI)')
        ->and(VatPreset::ReverseChargeEu->exemptionReasonOn(Date::of(2027, 1, 1)))->toBe('Autoliquidation');
});

it('sends domestic business invoices as Factur-X', function () {
    $resolve = fn ($builder) => (new DefaultFormatResolver)->resolve($builder->build(), new ResolvedCountries(new CountryFR));

    expect([$resolve(French::invoice())->format, $resolve(French::invoice())->profile])->toBe(['zugferd', 'en16931'])
        ->and($resolve(French::invoice()->buyer(fn ($b) => $b->consumer()))->format)->toBe('pdf');
});

it('calculates late payment penalties of the ECB rate plus 10 points', function () {
    $interest = DefaultInterest::forCountry(new CountryFR);
    $charges = $interest->calculate(Money::of('1000.00', 'EUR'), Date::of(2026, 6, 1), Date::of(2026, 8, 1));

    expect(array_map(fn ($c) => (string) $c->ratePercent, $charges))->toBe(['12.15', '12.40'])
        ->and((string) $interest->flatRate())->toBe('EUR 40.00');
});

it('charges consumers the legal interest rate of a business creditor', function () {
    $interest = DefaultInterest::forCountry(new CountryFR);
    $charges = $interest->calculate(Money::of('1000.00', 'EUR'), Date::of(2026, 7, 1), Date::of(2026, 8, 1), consumer: true);

    expect((string) $charges[0]->ratePercent)->toBe('2.75');
});

it('keeps invoices for ten years and calls a credit note "Avoir"', function () {
    expect((new CountryFR)->retention()->until(Date::of(2026, 10, 5))->toIsoString())->toBe('2036-12-31')
        ->and((new CountryFR)->correctionPolicy()->title('cancellation', 'fr'))->toBe('Avoir');
});

it('renders a Factur-X invoice with the French mentions that passes EN 16931', function () {
    $result = Quittio::create([], [new CountryFR])->make(French::invoice()->build())->render();

    expect($result->format())->toBe('zugferd')
        ->and($result->xml())->toContain('<ram:ID>S1</ram:ID>')
        ->and($result->xml())->toContain('pénalités')
        ->and(array_map(fn ($m) => $m->code, $result->validation()->errors()))->toBe([]);
});

it('passes the official EN 16931 validation', function () {
    $result = Quittio::create([], [new CountryFR])->addValidator(Official::kosit())->make(French::invoice()->build())->format('cii')->render();

    expect(array_map(fn ($m) => $m->code.' '.$m->message, $result->validation()->errors()))->toBe([]);
})->group('official')->skip(fn () => ! Official::available(), 'KoSIT validator or Java not available (run tools/schemas/download.sh).');
