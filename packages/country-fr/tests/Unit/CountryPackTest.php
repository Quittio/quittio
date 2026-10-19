<?php

declare(strict_types=1);

use Brick\Money\Money;
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Dunning\DefaultInterest;
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
