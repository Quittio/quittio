<?php

declare(strict_types=1);

use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;
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
