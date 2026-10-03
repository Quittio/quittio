<?php

declare(strict_types=1);

use Quittio\Core\Validation\Plausibility\ViesVerifier;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\CodeListRules;
use Quittio\Core\Validation\Rules\En16931Rules;
use Quittio\Core\Validation\Rules\PeppolRules;
use Quittio\Core\Validation\Rules\PlausibilityRules;
use Quittio\Core\Validation\Rules\QuittioRules;
use Quittio\Core\Validation\Rules\StructureRules;
use Quittio\Core\Validation\Rules\VatCategoryRules;

it('has a German and English message for every rule code', function () {
    $rules = [
        ...StructureRules::all(), ...En16931Rules::all(), ...VatCategoryRules::all(), ...CodeListRules::all(),
        ...QuittioRules::all(), ...PlausibilityRules::all(new ViesVerifier), ...PeppolRules::all(),
    ];
    $codes = array_map(fn (CallbackRule $r) => $r->code(), $rules);
    $codes = [...$codes, 'QUITTIO-PROFILE-REQUIRED', 'QUITTIO-PROFILE-NOT-ALLOWED', 'QUITTIO-PROFILE-EXTENDED-ONLY', 'QUITTIO-PROFILE-XRECHNUNG-EXTENSION-ONLY', 'QUITTIO-PROFILE-SUBLINES'];

    $translator = Quittio\Core\I18n\Translations::translator();
    foreach ($codes as $code) {
        foreach (['de', 'en'] as $locale) {
            expect($translator->has("quittio::rules.{$code}.message", $locale))->toBeTrue("Missing {$locale} message for {$code}");
        }
    }

    expect(count(array_unique($codes)))->toBe(count($codes));
});
