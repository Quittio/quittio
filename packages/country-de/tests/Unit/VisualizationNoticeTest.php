<?php

declare(strict_types=1);

use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;
use Quittio\Country\DE\CountryDE;

const VISUALIZATION_NOTICE = 'Lesbare Darstellung der E-Rechnung (XRechnung 3.0) Nr. RE-2026-0042 – inhaltsgleiches Mehrstück. Maßgeblich ist ausschließlich die XML-Datei.';

function visualizationQuittio(array $config = []): Quittio
{
    return Quittio::create($config, [new CountryDE]);
}

it('marks the PDF next to an XRechnung as readable copy (BMF letter of 15 October 2024, marginal no. 6)', function () {
    $result = visualizationQuittio()->make(Scenarios::make('simple')->buyerReference('04011000-12345-03'))->formats(['xrechnung-cii', 'html'])->render();

    expect((string) $result->as('html')->html())->toContain(VISUALIZATION_NOTICE)
        ->and($result->as('xrechnung-cii')->xml())->not->toContain('inhaltsgleiches Mehrstück');
});

it('does not mark ZUGFeRD, a PDF alone or a run without the notice', function () {
    $simple = fn () => Scenarios::make('simple')->buyerReference('04011000-12345-03');

    expect((string) visualizationQuittio()->make(Scenarios::make('simple'))->formats(['zugferd', 'html'])->render()->as('html')->html())->not->toContain('Lesbare Darstellung')
        ->and((string) visualizationQuittio()->make(Scenarios::make('simple'))->formats(['html'])->render()->html())->not->toContain('Lesbare Darstellung')
        ->and((string) visualizationQuittio(['options' => ['visualization_notice' => false]])->make($simple())->formats(['xrechnung-cii', 'html'])->render()->as('html')->html())->not->toContain('Lesbare Darstellung');
});
