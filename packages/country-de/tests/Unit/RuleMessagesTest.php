<?php

declare(strict_types=1);

use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\CodeListRules;
use Quittio\Core\Validation\Rules\En16931Rules;
use Quittio\Core\Validation\Rules\PeppolRules;
use Quittio\Core\Validation\Rules\PlausibilityRules;
use Quittio\Core\Validation\Rules\QuittioRules;
use Quittio\Core\Validation\Rules\StructureRules;
use Quittio\Core\Validation\Rules\VatCategoryRules;
use Quittio\Country\DE\Rules\UstgRules;
use Quittio\Country\DE\Rules\XRechnungRules;

/*
 * The English fallback message of a rule is a copy of its entry in lang/en/rules.php (used when
 * no translator is available); both must say the same, and every rule needs a German message.
 */

it('keeps the fallback messages of the rules in line with the English and German language files', function () {
    $files = [
        'quittio' => dirname(__DIR__, 3).'/core/resources/lang/%s/rules.php',
        'quittio-de' => dirname(__DIR__, 2).'/resources/lang/%s/rules.php',
    ];
    $rules = [
        ...CodeListRules::all(), ...En16931Rules::all(), ...PeppolRules::all(), ...PlausibilityRules::all(),
        ...QuittioRules::all(), ...StructureRules::all(), ...VatCategoryRules::all(), ...UstgRules::all(), ...XRechnungRules::all(),
    ];

    $problems = [];
    $checked = 0;
    foreach ($rules as $rule) {
        if (! $rule instanceof CallbackRule) {
            continue;
        }
        $file = $files[$rule->namespace()] ?? null;
        if ($file === null) {
            $problems[] = $rule->code().': unknown namespace '.$rule->namespace();

            continue;
        }
        $english = (require sprintf($file, 'en'))[$rule->code()]['message'] ?? null;
        $german = (require sprintf($file, 'de'))[$rule->code()]['message'] ?? null;
        if ($english !== $rule->fallbackMessage()) {
            $problems[] = sprintf('%s: fallback "%s", lang/en "%s"', $rule->code(), $rule->fallbackMessage(), $english ?? 'missing');
        }
        if ($german === null) {
            $problems[] = $rule->code().': no German message';
        }
        $checked++;
    }

    expect($problems)->toBe([])
        ->and($checked)->toBeGreaterThan(100);
});
