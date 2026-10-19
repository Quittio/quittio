<?php

declare(strict_types=1);

use Quittio\Core\I18n\ArrayTranslator;

it('takes the texts of the language for a regional locale before the fallback locale', function (string $locale, string $text) {
    expect((new ArrayTranslator)->get('quittio::labels.title_380', [], $locale))->toBe($text);
})->with([
    'de_CH' => ['de_CH', 'Rechnung'],
    'de-AT' => ['de-AT', 'Rechnung'],
    'fr_CH' => ['fr_CH', 'Facture'],
    'it_CH (no Italian texts)' => ['it_CH', 'Invoice'],
]);

it('names the language of a locale', function () {
    expect(ArrayTranslator::language('de_CH'))->toBe('de')
        ->and(ArrayTranslator::language('fr-CH'))->toBe('fr')
        ->and(ArrayTranslator::language('EN'))->toBe('en')
        ->and((new ArrayTranslator)->has('quittio::labels.title_380', 'de_CH'))->toBeFalse();
});
