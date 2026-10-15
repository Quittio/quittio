<?php

declare(strict_types=1);

use Brick\Money\Money;
use Quittio\Core\Support\Date;
use Quittio\Country\DE\Dunning\DefaultInterest;
use Quittio\Country\DE\Texts\LegalTexts;

it('describes default interest in German for every German locale', function (string $locale, string $start) {
    $charges = (new DefaultInterest)->calculate(Money::of('1000', 'EUR'), Date::parse('2026-08-16'), Date::parse('2026-10-03'), locale: $locale);

    expect($charges[0]->description)->toStartWith($start);
})->with([
    'de' => ['de', 'Verzugszinsen '],
    'de_AT' => ['de_AT', 'Verzugszinsen '],
    'en' => ['en', 'Default interest '],
    'fr (German fallback)' => ['fr', 'Verzugszinsen '],
]);

it('reads the legal texts from the language files of the pack', function () {
    $german = require dirname(__DIR__, 2).'/resources/lang/de/legal.php';
    $english = require dirname(__DIR__, 2).'/resources/lang/en/legal.php';

    expect(LegalTexts::text(LegalTexts::CONSUMER_DEFAULT))->toBe($german['consumer_default'])
        ->and(LegalTexts::text(LegalTexts::CONSUMER_DEFAULT, 'en_GB'))->toBe($english['consumer_default'])
        ->and(array_keys($german))->toBe(array_keys($english));
});
