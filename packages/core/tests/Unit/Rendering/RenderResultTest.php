<?php

declare(strict_types=1);

use Quittio\Core\Exceptions\LogicException;
use Quittio\Core\Quittio;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Testing\Scenarios;

it('links the results of one run and keeps one main format', function () {
    $result = Quittio::create()->make(Scenarios::simple()->build())->formats(['cii', 'ubl', 'html'])->render();

    expect($result->format())->toBe('cii')
        ->and($result->formats())->toBe(['cii', 'ubl', 'html'])
        ->and($result->as('ubl')->as('cii'))->toBe($result)
        ->and($result->as('ubl')->xml())->toContain('<Invoice ')
        ->and($result->as('html')->hasXml())->toBeFalse()
        ->and(fn () => $result->as('pdf'))->toThrow(LogicException::class, 'The format "pdf" was not rendered. Rendered formats: cii, ubl, html.');
});

it('describes the main output by extension, MIME type and file name', function (string $format, string $extension, string $mime) {
    $result = Quittio::create(['validation' => ['mode' => 'warn']])->make(Scenarios::simple()->build())->format($format)->render();

    expect($result->extension())->toBe($extension)
        ->and($result->mimeType())->toBe($mime)
        ->and($result->filename())->toBe('Rechnung_RE-2026-0042.'.$extension)
        ->and($result->filename('txt'))->toBe('Rechnung_RE-2026-0042.txt')
        ->and($result->contents())->not->toBe('');
})->with([
    ['cii', 'xml', 'application/xml'],
    ['html', 'html', 'text/html'],
    ['json', 'json', 'application/json'],
]);

it('fills the file name pattern with safe values', function () {
    $quittio = Quittio::create(['filename' => '{date}/{title}-{number}-{format}.{ext}']);
    $result = $quittio->make(Scenarios::simple()->number('RE 2026/42: "Ä"')->build())->format('cii')->render();

    expect($result->filename())->toBe('2026-10-03/Rechnung-RE_2026_42_Ä-cii.xml');
});

it('hashes every output it has', function () {
    $result = Quittio::create()->make(Scenarios::simple()->build())->formats(['cii', 'html'])->render();

    expect($result->hashes())->toBe(['xml' => hash('sha256', $result->xml())])
        ->and($result->as('html')->hashes())->toBe(['html' => hash('sha256', $result->as('html')->html())]);
});

it('refuses outputs the format does not produce', function (string $method) {
    Quittio::create()->make(Scenarios::simple()->build())->format('cii')->render()->{$method}();
})->with(['pdf', 'html'])->throws(LogicException::class, 'produces no');

it('prepares a run without rendering and keeps the chosen locale and countries', function () {
    $prepared = Quittio::create()->prepare(Scenarios::simple()->build(), new RenderOptions(formats: ['ubl'], locale: 'en'));

    expect($prepared->format())->toBe('ubl')
        ->and($prepared->locale)->toBe('en')
        ->and($prepared->data->totals?->amountDue->getAmount()->toString())->not->toBe('0.00');
});
