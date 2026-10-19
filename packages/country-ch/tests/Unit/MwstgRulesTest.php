<?php

declare(strict_types=1);

use Quittio\Core\Enums\UnitCode;
use Quittio\Country\CH\Rules\MwstgRules;
use Quittio\Country\CH\Tax\VatPreset;
use Quittio\Country\CH\Tests\Support\Swiss;

it('accepts a complete Swiss invoice', function () {
    expect(Swiss::codes(Swiss::validate(Swiss::invoice())))->toBe([]);
});

it('requires the place of the seller and of the buyer', function () {
    $result = Swiss::validate(Swiss::invoice()
        ->seller(fn ($s) => $s->address('Bahnhofstrasse 12', '8001', null, 'CH'))
        ->buyer(fn ($b) => $b->address('Marktgasse 28', '3011', null, 'CH')));

    expect(Swiss::codes($result))->toBe(['CH-MWSTG-01', 'CH-MWSTG-04']);
});

it('lets a till receipt up to CHF 400 leave out the buyer', function () {
    $receipt = Swiss::base()->buyer(fn ($b) => $b->name('Barverkauf')->address(null, null, null, 'CH'))
        ->line(fn ($l) => $l->name('Kabel')->quantity(2, UnitCode::Piece)->netPrice('150.00')->vat(VatPreset::Standard));

    expect(Swiss::codes(Swiss::validate($receipt)))->toBe([])
        ->and(MwstgRules::isTillReceipt(Swiss::calculated($receipt)))->toBeTrue();
});

it('requires the UID of a seller that charges VAT', function () {
    $withoutUid = Swiss::invoice()->seller(fn ($s) => $s->vatId(null));
    $notRegistered = Swiss::base()->seller(fn ($s) => $s->vatId(null))
        ->line(fn ($l) => $l->name('Grafik')->quantity(1, UnitCode::Piece)->netPrice('500.00')->vat(VatPreset::NotRegistered));

    expect(Swiss::codes(Swiss::validate($withoutUid)))->toBe(['CH-MWSTG-02'])
        ->and(Swiss::codes(Swiss::validate($notRegistered)))->toBe([]);
});

it('warns about a UID without the note of the VAT registration', function () {
    expect(Swiss::codes(Swiss::validate(Swiss::invoice()->seller(fn ($s) => $s->vatId('CHE-123.456.788')))))->toBe(['CH-MWSTG-03'])
        ->and(Swiss::codes(Swiss::validate(Swiss::invoice()->seller(fn ($s) => $s->vatId('CHE-123.456.788 TVA')))))->toBe([]);
});

it('forbids VAT on invoices of sellers that are not registered', function () {
    $mixed = Swiss::base()
        ->line(fn ($l) => $l->name('Grafik')->quantity(1, UnitCode::Piece)->netPrice('500.00')->vat(VatPreset::NotRegistered))
        ->line(fn ($l) => $l->name('Druck')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatPreset::Standard));

    expect(Swiss::codes(Swiss::validate($mixed)))->toContain('CH-MWSTG-05');
});

it('writes the messages in French and Italian', function (string $locale, string $start) {
    $result = Swiss::validate(Swiss::invoice()->seller(fn ($s) => $s->address('Bahnhofstrasse 12', '8001', null, 'CH')), $locale);

    expect($result->errors()[0]->message)->toStartWith($start);
})->with([
    ['fr', 'Le nom et le lieu du fournisseur'],
    ['it', 'Devono essere indicati il nome e il luogo del fornitore'],
    ['de_CH', 'Name und Ort des Leistungserbringers'],
]);
