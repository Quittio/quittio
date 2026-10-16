<?php

declare(strict_types=1);

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Quittio;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\RuleOverrides;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;

function overrideRule(string $code): Rule
{
    return CallbackRule::l3($code, 'Fallback of '.$code, fn () => null);
}

function overrideMessage(string $code, Severity $severity = Severity::Error): ValidationMessage
{
    return new ValidationMessage($severity, $code, 'Message of '.$code, ValidationLevel::L3, 'BT-1', 'number');
}

it('drops disabled rules by exact code and by prefix', function () {
    $overrides = new RuleOverrides(disabled: ['QUITTIO-NOTE', 'DE-USTG-*']);
    $codes = array_map(fn (Rule $r) => $r->code(), $overrides->apply([
        overrideRule('QUITTIO-NOTE'), overrideRule('QUITTIO-NOTES'), overrideRule('DE-USTG-08'), overrideRule('DE-USTG-15'), overrideRule('DE-VAT-RATE'),
    ]));

    expect($codes)->toBe(['QUITTIO-NOTES', 'DE-VAT-RATE']);
});

it('replaces a rule in place', function () {
    $replacement = new class implements Rule
    {
        public function code(): string
        {
            return 'DE-USTG-15';
        }

        public function level(): ValidationLevel
        {
            return ValidationLevel::L7;
        }

        public function check(InvoiceData $invoice, ValidationContext $context): iterable
        {
            return [];
        }
    };
    $rules = (new RuleOverrides(replacements: ['DE-USTG-15' => $replacement]))->apply([overrideRule('DE-USTG-08'), overrideRule('DE-USTG-15'), overrideRule('DE-USTG-16')]);

    expect($rules[1])->toBe($replacement)
        ->and(array_map(fn (Rule $r) => $r->code(), $rules))->toBe(['DE-USTG-08', 'DE-USTG-15', 'DE-USTG-16']);
});

it('drops findings of disabled codes, also those a rule reports under another code', function () {
    $messages = (new RuleOverrides(disabled: ['QUITTIO-PROFILE-NOT-ALLOWED']))->applyToMessages([
        overrideMessage('QUITTIO-PROFILE-NOT-ALLOWED', Severity::Warning), overrideMessage('QUITTIO-PROFILE-REQUIRED'),
    ]);

    expect(array_map(fn (ValidationMessage $m) => $m->code, $messages))->toBe(['QUITTIO-PROFILE-REQUIRED']);
});

it('changes severities, an exact code before the longest prefix', function () {
    $overrides = new RuleOverrides(severities: ['DE-*' => Severity::Info, 'DE-USTG-*' => Severity::Warning, 'DE-USTG-15' => Severity::Error]);
    $messages = $overrides->applyToMessages([
        overrideMessage('DE-USTG-08'), overrideMessage('DE-USTG-15', Severity::Warning), overrideMessage('DE-VAT-RATE', Severity::Warning), overrideMessage('QUITTIO-NOTE'),
    ]);

    expect(array_map(fn (ValidationMessage $m) => $m->severity, $messages))->toBe([Severity::Warning, Severity::Error, Severity::Info, Severity::Error])
        ->and($messages[0]->path)->toBe('number')
        ->and($messages[0]->bt)->toBe('BT-1');
});

it('protects the official rules unless allowed', function (string $pattern) {
    expect(fn () => new RuleOverrides(disabled: [$pattern]))->toThrow(ConfigurationException::class, 'official')
        ->and(fn () => new RuleOverrides(severities: [$pattern => Severity::Warning]))->toThrow(ConfigurationException::class, 'official')
        ->and((new RuleOverrides(disabled: [$pattern], allowOfficial: true))->isDisabled('BR-CO-10'))->toBeTrue();
})->with(['BR-CO-10', 'BR-*', 'B*', '*']);

it('reads the configuration keys', function () {
    $overrides = RuleOverrides::fromArray(['disabled' => ['DE-USTG-15'], 'severity' => ['DE-USTG-08' => 'WARNING'], 'allow_official' => false]);

    expect($overrides->disabled)->toBe(['DE-USTG-15'])
        ->and($overrides->severities)->toBe(['DE-USTG-08' => Severity::Warning])
        ->and($overrides->patterns())->toBe(['DE-USTG-15', 'DE-USTG-08'])
        ->and(fn () => RuleOverrides::fromArray(['severity' => ['DE-USTG-08' => 'fatal']]))->toThrow(ConfigurationException::class, 'Unknown severity');
});

it('merges overrides with the later ones winning', function () {
    $merged = (new RuleOverrides(disabled: ['A-1'], severities: ['A-2' => Severity::Info]))
        ->merge(new RuleOverrides(disabled: ['A-1', 'A-3'], severities: ['A-2' => Severity::Warning]));

    expect($merged->disabled)->toBe(['A-1', 'A-3'])
        ->and($merged->severities)->toBe(['A-2' => Severity::Warning])
        ->and((new RuleOverrides)->isEmpty())->toBeTrue();
});

/**
 * @return list<string> severity and code of the findings of the simple scenario
 */
function overriddenFindings(Quittio $quittio): array
{
    return array_map(
        fn (ValidationMessage $m) => $m->severity->value.' '.$m->code,
        $quittio->check(Scenarios::simple()->build(), new RenderOptions(formats: ['zugferd']))->messages,
    );
}

it('applies configured overrides to the PHP rules of a run', function () {
    expect(overriddenFindings(Quittio::create()))->toContain('warning QUITTIO-GENERIC-COUNTRY')
        ->and(overriddenFindings(Quittio::create(['validation' => ['rules' => ['disabled' => ['QUITTIO-GENERIC-COUNTRY']]]])))->not->toContain('warning QUITTIO-GENERIC-COUNTRY')
        ->and(overriddenFindings(Quittio::create(['validation' => ['rules' => ['severity' => ['QUITTIO-GENERIC-*' => 'info']]]])))->toContain('info QUITTIO-GENERIC-COUNTRY');
});

it('keeps the overrides when the run switches levels off', function () {
    $driver = (new PhpRulesDriver)->withOverrides(new RuleOverrides(disabled: ['QUITTIO-GENERIC-COUNTRY']));

    expect($driver->withLevels([ValidationLevel::L3])->overrides()->disabled)->toBe(['QUITTIO-GENERIC-COUNTRY'])
        ->and($driver->withVatIdVerifier(null)->overrides()->disabled)->toBe(['QUITTIO-GENERIC-COUNTRY']);
});

it('refuses codes that no rule has', function () {
    expect(fn () => Quittio::create(['validation' => ['rules' => ['disabled' => ['QUITTIO-GENERIC-COUNTRI']]]]))
        ->toThrow(ConfigurationException::class, 'No rule has the code "QUITTIO-GENERIC-COUNTRI"')
        ->and(fn () => Quittio::create()->configureRules(new RuleOverrides(disabled: ['NOPE-*'])))
        ->toThrow(ConfigurationException::class);
});

it('knows the codes of the built-in rules and of the findings of the field matrix', function () {
    $codes = Quittio::create()->ruleCodes();

    expect($codes)->toContain('BR-02', 'QUITTIO-GENERIC-COUNTRY', 'QUITTIO-PROFILE-REQUIRED', 'PEPPOL-EN16931-R001')
        ->and($codes)->toBe(array_values(array_unique($codes)));
});
