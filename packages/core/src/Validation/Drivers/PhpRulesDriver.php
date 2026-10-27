<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Drivers;

use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Profiles\Capabilities\ReplacesRules;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Validation\Plausibility\VatIdVerifier;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\RuleOverrides;
use Quittio\Core\Validation\Rules\CodeListRules;
use Quittio\Core\Validation\Rules\En16931Rules;
use Quittio\Core\Validation\Rules\FieldMatrixRule;
use Quittio\Core\Validation\Rules\PlausibilityRules;
use Quittio\Core\Validation\Rules\QuittioRules;
use Quittio\Core\Validation\Rules\StructureRules;
use Quittio\Core\Validation\Rules\VatCategoryRules;
use Quittio\Core\Validation\ValidationDriver;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;

/**
 * Built-in PHP rules: levels L1 (structure), L2 (profile), L3 (business rules) and L7 (plausibility).
 *
 * Rules of the profile and of the country packs are added per invoice.
 */
final class PhpRulesDriver implements ValidationDriver
{
    /**
     * @param  list<Rule>  $additionalRules
     * @param  list<ValidationLevel>  $levels  levels to run; L7 can be switched off
     * @param  RuleOverrides  $overrides  rules switched off, re-rated or replaced by code
     */
    public function __construct(
        private readonly array $additionalRules = [],
        private readonly ?VatIdVerifier $vatIdVerifier = null,
        private readonly array $levels = [ValidationLevel::L1, ValidationLevel::L2, ValidationLevel::L3, ValidationLevel::L7],
        private readonly RuleOverrides $overrides = new RuleOverrides,
    ) {}

    /**
     * Returns "php", the name of this check in the validation.levels configuration.
     */
    public function name(): string
    {
        return 'php';
    }

    public function levels(): array
    {
        return $this->levels;
    }

    /**
     * The same driver (additional rules, VAT identifier verifier, overrides) with other levels.
     *
     * @param  list<ValidationLevel>  $levels
     */
    public function withLevels(array $levels): self
    {
        return new self($this->additionalRules, $this->vatIdVerifier, $levels, $this->overrides);
    }

    /**
     * The same driver with an online VAT identifier check (e.g. VIES).
     */
    public function withVatIdVerifier(?VatIdVerifier $verifier): self
    {
        return new self($this->additionalRules, $verifier, $this->levels, $this->overrides);
    }

    /**
     * The same driver with other rule overrides (rules switched off, re-rated or replaced).
     */
    public function withOverrides(RuleOverrides $overrides): self
    {
        return new self($this->additionalRules, $this->vatIdVerifier, $this->levels, $overrides);
    }

    /**
     * The rule overrides of this driver.
     */
    public function overrides(): RuleOverrides
    {
        return $this->overrides;
    }

    public function supports(ValidationSubject $subject): bool
    {
        return true;
    }

    /**
     * Runs the rules() of the configured levels against the invoice data, after loading the message
     * catalogs of the country packs. Applies the overrides to the findings and reports identical
     * findings once.
     */
    public function validate(ValidationSubject $subject): ValidationResult
    {
        $context = $subject->context;
        $messages = [];

        // Country packs ship their own message catalogs.
        foreach ($context->countryPacks as $pack) {
            $path = $pack->translationPath();
            if ($path !== null) {
                $context->translator()->addPath($path, 'quittio-'.strtolower($pack->code()));
            }
        }

        foreach ($this->rules($subject) as $rule) {
            if (! in_array($rule->level(), $this->levels, true)) {
                continue;
            }
            foreach ($rule->check($subject->invoice, $context) as $message) {
                $messages[] = $message;
            }
        }

        return new ValidationResult($this->deduplicate($this->overrides->applyToMessages($messages)), $this->levels);
    }

    /**
     * The rules for the subject after the overrides: the built-in rules, those of the profile
     * and the country packs without those the profile replaces (ReplacesRules), then the
     * additional rules.
     *
     * @return list<Rule>
     */
    public function rules(ValidationSubject $subject): array
    {
        $context = $subject->context;
        $rules = [
            ...StructureRules::all(),
            new FieldMatrixRule,
            ...En16931Rules::all(),
            ...VatCategoryRules::all(),
            ...CodeListRules::all(),
            ...QuittioRules::all(),
            ...PlausibilityRules::all($this->vatIdVerifier),
            ...$context->profile->rules(),
        ];

        foreach ($context->countryPacks as $index => $pack) {
            foreach ($index === 0 ? $pack->rules() : $pack->buyerCountryRules() as $rule) {
                $rules[] = $rule;
            }
        }

        // Rules the profile replaces with its own (e.g. BR-CO-16 by BR-DEX-09 in the XRechnung extension).
        if ($context->profile instanceof ReplacesRules) {
            $syntax = $context->option('syntax');
            $replaced = $context->profile->replacedRules($syntax instanceof Syntax ? $syntax : null);
            $rules = array_filter($rules, static fn (Rule $rule): bool => ! in_array($rule->code(), $replaced, true));
        }

        return $this->overrides->apply([...array_values($rules), ...$this->additionalRules]);
    }

    /**
     * Every rule and finding code this driver can report with the given profiles and country
     * packs (without the overrides), e.g. to check configured overrides for typing errors.
     *
     * @param  iterable<Profile>  $profiles
     * @param  iterable<CountryPack>  $packs
     * @return list<string>
     */
    public function knownCodes(iterable $profiles, iterable $packs): array
    {
        $rules = [
            ...StructureRules::all(), new FieldMatrixRule, ...En16931Rules::all(), ...VatCategoryRules::all(),
            ...CodeListRules::all(), ...QuittioRules::all(), ...PlausibilityRules::all($this->vatIdVerifier), ...$this->additionalRules,
        ];
        foreach ($profiles as $profile) {
            $rules = [...$rules, ...$profile->rules()];
        }
        foreach ($packs as $pack) {
            $rules = [...$rules, ...$pack->rules(), ...$pack->buyerCountryRules()];
        }

        return array_values(array_unique([...array_map(static fn (Rule $rule): string => $rule->code(), $rules), ...FieldMatrixRule::CODES]));
    }

    /**
     * Profile and country rules may overlap; identical findings are reported once.
     *
     * @param  list<ValidationMessage>  $messages
     * @return list<ValidationMessage>
     */
    private function deduplicate(array $messages): array
    {
        $unique = [];
        foreach ($messages as $message) {
            $unique[$message->code.'|'.$message->path.'|'.$message->message] ??= $message;
        }

        return array_values($unique);
    }
}
