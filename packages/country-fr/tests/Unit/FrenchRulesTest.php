<?php

declare(strict_types=1);

use Quittio\Core\Countries\CountryRegistry;
use Quittio\Core\Countries\DefaultCountryResolver;
use Quittio\Core\Data\Meta;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\Syntax;
use Quittio\Country\FR\CountryFR;
use Quittio\Country\FR\Support\Siren;
use Quittio\Country\FR\Tax\VatPreset;
use Quittio\Country\FR\Tests\Support\French;

it('accepts a complete French invoice', function () {
    expect(French::codes(French::validate(French::invoice())))->toBe([]);
});

it('requires the SIREN and VAT number of the seller', function () {
    expect(French::codes(French::validate(French::invoice()->seller(fn ($s) => $s->legalRegistration(null)))))->toBe(['FR-CGI-01'])
        ->and(French::codes(French::validate(French::invoice()->seller(fn ($s) => $s->legalRegistration('732829321', '0002')))))->toBe(['FR-CGI-01'])
        ->and(French::codes(French::validate(French::invoice()->seller(fn ($s) => $s->legalRegistration('73282932000009', '0009')))))->toBe([])
        ->and(French::codes(French::validate(French::invoice()->seller(fn ($s) => $s->vatId(null)))))->toBe(['FR-CGI-02']);
});

it('leads the invoices of sellers in Monaco without asking for a SIREN', function () {
    $monaco = French::invoice()->seller(fn ($s) => $s->legalRegistration(null)->address('7 avenue de Grande-Bretagne', '98000', 'Monaco', 'MC'));
    $toMonaco = French::invoice()->buyer(fn ($b) => $b->legalRegistration(null)->address('2 boulevard des Moulins', '98000', 'Monaco', 'MC'));

    expect((new DefaultCountryResolver(new CountryRegistry([new CountryFR])))->resolve($monaco->build())->leading)->toBeInstanceOf(CountryFR::class)
        ->and(French::codes(French::validate($monaco)))->toBe([])
        ->and(French::codes(French::validate($toMonaco)))->toBe([]);
});

it('lets a small business under the franchise invoice without VAT number but not with VAT', function () {
    $franchise = French::base()->seller(fn ($s) => $s->vatId(null))
        ->line(fn ($l) => $l->name('Graphisme')->quantity(1, UnitCode::Piece)->netPrice('400.00')->vat(VatPreset::SmallBusiness));

    expect(French::codes(French::validate($franchise)))->toBe([])
        ->and(French::codes(French::validate(French::invoice()->flag(Meta::FLAG_SMALL_BUSINESS))))->toBe(['FR-CGI-06']);
});

it('requires complete addresses', function () {
    expect(French::codes(French::validate(French::invoice()->buyer(fn ($b) => $b->address(null, '75001', 'Paris', 'FR')))))->toBe(['FR-CGI-03']);
});

it('requires "Autoliquidation" and exemption texts', function () {
    $own = French::base()->line(fn ($l) => $l->name('Travaux')->quantity(1, UnitCode::Piece)->netPrice('5000.00')->vat(VatCategory::ReverseCharge, '0', 'Sans TVA'));
    $preset = French::base()->line(fn ($l) => $l->name('Travaux')->quantity(1, UnitCode::Piece)->netPrice('5000.00')->vat(VatPreset::ReverseChargeDomestic));
    $codeOnly = French::base()->line(fn ($l) => $l->name('Formation')->quantity(1, UnitCode::Piece)->netPrice('500.00')->vat(VatCategory::Exempt, '0', null, 'VATEX-EU-132'));

    expect(French::codes(French::validate($own)))->toBe(['FR-CGI-04'])
        ->and(French::codes(French::validate($preset)))->toBe([])
        ->and(French::codes(French::validate($codeOnly)))->toBe(['FR-CGI-05']);
});

it('requires "Autofacturation" on self-billed invoices, as a warning in XML', function () {
    $selfBilled = French::invoice()->type(InvoiceTypeCode::SelfBilledInvoice);

    expect(French::validate($selfBilled)->errors()[0]->code ?? null)->toBe('FR-CGI-07')
        ->and(French::validate($selfBilled, Syntax::Cii)->warnings()[0]->code ?? null)->toBe('FR-CGI-07')
        ->and(French::codes(French::validate($selfBilled->note('Autofacturation'))))->toBe([]);
});

it('warns about the mentions of the reform from 1 September 2026', function (string $issueDate, array $codes) {
    $builder = French::invoice()->issueDate($issueDate)->deliveryDate('2026-08-30')->businessProcess(null)->buyer(fn ($b) => $b->legalRegistration(null));

    expect(French::codes(French::validate($builder)))->toBe($codes);
})->with([
    'before' => ['2026-08-31', []],
    'from' => ['2026-09-01', ['FR-CGI-08', 'FR-CGI-09']],
]);

it('requires the due date and the late payment mentions between businesses', function () {
    $withoutDueDate = French::invoice()->build()->with(['dueDate' => null, 'paymentTerms' => null]);

    expect(French::codes(French::validateData($withoutDueDate)))->toBe(['FR-COM-01'])
        ->and(French::codes(French::validate(French::invoice(), settings: ['payment_mentions' => false])))->toBe(['FR-COM-02'])
        ->and(French::codes(French::validate(French::invoice()->buyer(fn ($b) => $b->consumer()), settings: ['payment_mentions' => false])))->toBe([]);
});

it('checks SIREN and SIRET numbers', function () {
    expect(Siren::isSiren('732 829 320'))->toBeTrue()
        ->and(Siren::isSiren('732829321'))->toBeFalse()
        ->and(Siren::isSiret('73282932000009'))->toBeTrue()
        ->and(Siren::isSiret('73282932000010'))->toBeFalse()
        ->and(Siren::of('FR44732829320'))->toBe('732829320')
        ->and(Siren::of('73282932000009'))->toBe('732829320')
        ->and(Siren::of('123'))->toBeNull();
});
