<?php

declare(strict_types=1);

use Quittio\Core\Quittio;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\Rules\CodeListRules;
use Quittio\Core\Validation\Rules\En16931Rules;
use Quittio\Core\Validation\Rules\FieldMatrixRule;
use Quittio\Core\Validation\Rules\PlausibilityRules;
use Quittio\Core\Validation\Rules\QuittioRules;
use Quittio\Core\Validation\Rules\StructureRules;
use Quittio\Core\Validation\Rules\VatCategoryRules;
use Quittio\Country\AT\CountryAT;
use Quittio\Country\DE\CountryDE;

/*
 * Rule overrides address rules by code, so a code belongs to one rule: unique within the core
 * rules, the rules of a country pack and the rules of a profile, and never shared between them.
 * Profiles may share rules with each other (XRechnung and its extension run the same BR-DE rules).
 */
it('gives every rule its own code across core, profiles and country packs', function () {
    $quittio = Quittio::create([], [new CountryDE, new CountryAT]);
    $groups = ['core' => [
        ...StructureRules::all(), ...En16931Rules::all(), ...VatCategoryRules::all(), ...CodeListRules::all(),
        ...QuittioRules::all(), ...PlausibilityRules::all(), new FieldMatrixRule,
    ]];
    foreach ($quittio->countries()->all() as $pack) {
        $groups['pack '.$pack->code()] = [...$pack->rules(), ...$pack->buyerCountryRules()];
    }
    $profileCodes = [];
    foreach ($quittio->profiles()->all() as $profile) {
        $codes = array_map(fn (Rule $rule) => $rule->code(), $profile->rules());
        expect(count(array_unique($codes)))->toBe(count($codes), 'Duplicate rule code in profile '.$profile->key());
        $profileCodes = [...$profileCodes, ...$codes];
    }

    $owners = [];
    foreach ($groups as $group => $rules) {
        $codes = array_map(fn (Rule $rule) => $rule->code(), $rules);
        expect(count(array_unique($codes)))->toBe(count($codes), 'Duplicate rule code in '.$group);
        foreach ($codes as $code) {
            $owners[$code][] = $group;
        }
    }
    foreach (array_unique($profileCodes) as $code) {
        $owners[$code][] = 'profiles';
    }

    expect(array_filter($owners, fn (array $groups) => count($groups) > 1))->toBe([]);
});
