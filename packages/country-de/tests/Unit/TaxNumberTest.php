<?php

declare(strict_types=1);

use Quittio\Country\DE\Support\TaxNumber;

/**
 * Examples of "Prüfung der Steuer- und Steueridentifikationsnummer" (ELSTER, table 4-1):
 * state => [ELSTER format, notation on tax notices].
 *
 * @return array<string, array{string, string, string}>
 */
function officialTaxNumbers(): array
{
    return [
        'Baden-Württemberg' => ['BW', '2866081508156', '66815/08156'],
        'Bayern (München)' => ['BY', '9198081508152', '198/815/08152'],
        'Bayern (Nürnberg)' => ['BY', '9296081508153', '296/815/08153'],
        'Berlin' => ['BE', '1197081508154', '97/815/08154'],
        'Brandenburg' => ['BB', '3098081508157', '098/815/08157'],
        'Bremen' => ['HB', '2497012301233', '97 123 01233'],
        'Hamburg' => ['HH', '2293081508150', '93/815/08150'],
        'Hessen' => ['HE', '2653081508158', '053 815 08158'],
        'Mecklenburg-Vorpommern' => ['MV', '4098081508157', '098/815/08157'],
        'Niedersachsen' => ['NI', '2388081508158', '88/815/08158'],
        'NRW (54)' => ['NW', '5400081508159', '400/8150/8159'],
        'NRW (55)' => ['NW', '5500081508151', '500/8150/8151'],
        'NRW (56)' => ['NW', '5600081508154', '600/8150/8154'],
        'Rheinland-Pfalz' => ['RP', '2799081508152', '99/815/08152'],
        'Saarland' => ['SL', '1096081508187', '096/815/08187'],
        'Sachsen' => ['SN', '3248081508156', '248/815/08156'],
        'Sachsen-Anhalt' => ['ST', '3198081508152', '198/815/08152'],
        'Schleswig-Holstein' => ['SH', '2138081508154', '38/815/08154'],
        'Thüringen' => ['TH', '4198081508152', '198/815/08152'],
    ];
}

it('accepts the official examples in the ELSTER format and in the notation of the state', function (string $state, string $elster, string $notation) {
    $fromElster = TaxNumber::parse($elster);
    $fromNotation = TaxNumber::parse($notation, $state);

    expect($fromElster?->state)->toBe($state)
        ->and($fromNotation?->elster)->toBe($elster)
        ->and(TaxNumber::validate($notation))->toBeTrue()
        ->and(TaxNumber::parse(substr($elster, 0, 4).substr($elster, 5))?->elster)->toBe($elster);
})->with(officialTaxNumbers());

it('rejects a wrong check digit in every state', function (string $state, string $elster) {
    for ($digit = 0; $digit <= 9; $digit++) {
        if ((string) $digit !== $elster[12]) {
            expect(TaxNumber::parse(substr($elster, 0, 12).$digit))->toBeNull();
        }
    }
})->with(officialTaxNumbers());

it('formats the notation of the tax notices', function () {
    expect(TaxNumber::parse('9198081508152')?->formatted())->toBe('198/815/08152')
        ->and(TaxNumber::parse('5400081508159')?->formatted())->toBe('400/8150/8159')
        ->and(TaxNumber::parse('2653081508158')?->formatted())->toBe('053 815 08158')
        ->and(TaxNumber::parse('2497012301233')?->formatted())->toBe('97 123 01233')
        ->and(TaxNumber::parse('2293081508150')?->formatted())->toBe('93/815/08150');
});

it('reads variants of the notations', function (string $value, string $state, string $elster) {
    // Several states share a notation: without the state any valid interpretation counts.
    expect(TaxNumber::parse($value, $state)?->elster)->toBe($elster)
        ->and(TaxNumber::validate($value))->toBeTrue();
})->with([
    'Hesse without leading 0' => ['53 815 08158', 'HE', '2653081508158'],
    'old Rhineland-Palatinate notation' => ['99/815/0815/2', 'RP', '2799081508152'],
    'Schleswig-Holstein with spaces' => ['38 815 08154', 'SH', '2138081508154'],
    'digits only' => ['19881508152', 'BY', '9198081508152'],
]);

it('applies the formal checks of the ELSTER format', function (string $withoutCheckDigit) {
    for ($digit = 0; $digit <= 9; $digit++) {
        expect(TaxNumber::parse($withoutCheckDigit.$digit))->toBeNull();
    }
})->with([
    'fifth digit not 0' => ['919818150815'],
    'district 000' => ['919800000815'],
    'district 999' => ['919809990815'],
    'Bavarian group district below 100' => ['919800990815'],
    'NRW district 0000' => ['540000000815'],
    'unknown state prefix' => ['789908150815'],
]);

it('tells invalid, unverifiable and VAT identifiers apart', function () {
    expect(TaxNumber::validate('11/111/11111'))->toBeFalse()
        ->and(TaxNumber::validate('12345'))->toBeFalse()
        ->and(TaxNumber::validate('DE123456789'))->toBeFalse()
        ->and(TaxNumber::looksLikeVatId('DE 123456789'))->toBeTrue()
        ->and(TaxNumber::looksLikeVatId('198/815/08152'))->toBeFalse()
        // Bavarian notation without the tax office number: cannot be checked.
        ->and(TaxNumber::validate('815/08152'))->toBeNull()
        ->and(TaxNumber::validate('815/08152', 'NW'))->toBeFalse()
        // A state restricts the interpretation of a notation.
        ->and(TaxNumber::parse('198/815/08152', 'ST')?->elster)->toBe('3198081508152')
        ->and(TaxNumber::parse('198/815/08152', 'BE'))->toBeNull();
});
