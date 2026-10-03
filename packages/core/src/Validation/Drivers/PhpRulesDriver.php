<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Drivers;

use Quittio\Core\Validation\Plausibility\VatIdVerifier;
use Quittio\Core\Validation\Rule;
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
     */
    public function __construct(
        private readonly array $additionalRules = [],
        private readonly ?VatIdVerifier $vatIdVerifier = null,
        private readonly array $levels = [ValidationLevel::L1, ValidationLevel::L2, ValidationLevel::L3, ValidationLevel::L7],
    ) {}

    public function name(): string
    {
        return 'php';
    }

    public function levels(): array
    {
        return $this->levels;
    }

    public function supports(ValidationSubject $subject): bool
    {
        return true;
    }

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

        return new ValidationResult($this->deduplicate($messages), $this->levels);
    }

    /**
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

        return [...$rules, ...$this->additionalRules];
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
