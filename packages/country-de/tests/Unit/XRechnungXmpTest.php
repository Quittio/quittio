<?php

declare(strict_types=1);

use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;
use Quittio\Country\DE\CountryDE;

it('describes embedded XRechnung with its version and file name (ZUGFeRD 2.x XMP)', function (string $profile) {
    $pdf = Quittio::create([], [new CountryDE])->make(Scenarios::make('simple')->buyerReference('04011000-12345-03'))
        ->format('zugferd')->profile($profile)->validate(throwOnError: false)->render()->pdf();

    expect($pdf)->toContain('<fx:DocumentFileName>xrechnung.xml</fx:DocumentFileName>')
        ->and($pdf)->toContain('<fx:Version>3.0</fx:Version>')
        ->and($pdf)->toContain('<fx:ConformanceLevel>XRECHNUNG</fx:ConformanceLevel>')
        ->and($pdf)->toContain('(XRechnung Invoice)')
        ->and($pdf)->not->toContain('(Factur-X/ZUGFeRD Invoice)');
})->with(['xrechnung', 'xrechnung-extension']);
