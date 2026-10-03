<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use BackedEnum;
use Brick\Math\BigNumber;
use Brick\Money\Money;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Mapping\FieldMatrix;
use Quittio\Core\Profiles\ProfileLevel;
use Quittio\Core\Support\Date;
use Quittio\Core\Validation\Messages;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;

/**
 * Mandatory and allowed fields of the selected profile (level L2), derived from the
 * mapping attributes of the data model and the profile's additional requirements.
 */
final class FieldMatrixRule implements Rule
{
    public function code(): string
    {
        return 'QUITTIO-PROFILE';
    }

    public function level(): ValidationLevel
    {
        return ValidationLevel::L2;
    }

    public function check(InvoiceData $invoice, ValidationContext $context): iterable
    {
        $messages = [];
        $this->walk($invoice, '', null, $context, $messages);

        $hasSubLines = array_filter($invoice->lines, static fn ($line): bool => $line->subLines !== []) !== [];
        if ($hasSubLines && ! in_array($context->profile->key(), ['extended', 'xrechnung-extension'], true)) {
            $messages[] = $this->message($context, Severity::Error, 'QUITTIO-PROFILE-SUBLINES', 'Sub lines are supported only in the profiles EXTENDED and XRechnung extension, not in :profile.', 'lines', null);
        }

        return $messages;
    }

    /**
     * @param  list<ValidationMessage>  $messages
     */
    private function walk(object $dto, string $path, ?string $parentProperty, ValidationContext $context, array &$messages): void
    {
        $profile = $context->profile;
        $values = get_object_vars($dto);

        foreach (FieldMatrix::fields($dto::class) as $name => $field) {
            $value = $values[$name] ?? null;
            $childPath = $path === '' ? $name : $path.'.'.$name;
            $bt = FieldMatrix::businessTerm($dto::class, $name, $parentProperty);
            $present = ! ($value === null || $value === '' || $value === []);

            if ($present) {
                if ($field->extendedOnly && $profile->level() !== ProfileLevel::Extended) {
                    $messages[] = $this->message($context, Severity::Error, 'QUITTIO-PROFILE-EXTENDED-ONLY', 'The field :field is available only in profile EXTENDED, not in :profile.', $childPath, $bt);
                } elseif ($field->xrechnungExtensionOnly && $profile->key() !== 'xrechnung-extension') {
                    $messages[] = $this->message($context, Severity::Error, 'QUITTIO-PROFILE-XRECHNUNG-EXTENSION-ONLY', 'The field :field is available only in the XRechnung extension, not in :profile.', $childPath, $bt);
                } elseif (! $field->pdfOnly && ! $field->internal && ! $field->computed && $bt !== null && ! $profile->level()->includes($field->level)) {
                    $messages[] = $this->message($context, Severity::Warning, 'QUITTIO-PROFILE-NOT-ALLOWED', 'The field :field (:bt) is not part of profile :profile and will not be written to the XML.', $childPath, $bt);
                }
            } elseif ($bt !== null && $this->isRequired($bt, $field->requiredIn, $context)) {
                $messages[] = $this->message($context, Severity::Error, 'QUITTIO-PROFILE-REQUIRED', 'The field :field (:bt) is mandatory in profile :profile.', $childPath, $bt);
            }

            if (! $present || $field->pdfOnly) {
                continue;
            }

            foreach (is_array($value) ? $value : [$value] as $index => $child) {
                if ($this->isDto($child)) {
                    $this->walk($child, is_array($value) ? $childPath.'['.$index.']' : $childPath, $name, $context, $messages);
                }
            }
        }
    }

    /**
     * @param  list<string>  $requiredIn
     */
    private function isRequired(string $bt, array $requiredIn, ValidationContext $context): bool
    {
        $profile = $context->profile;
        if ($bt === 'BT-23' && $profile->defaultBusinessProcess() !== null) {
            return false;
        }

        return in_array($profile->key(), $requiredIn, true) || in_array($bt, $profile->requiredBusinessTerms(), true);
    }

    /**
     * @phpstan-assert-if-true object $value
     */
    private function isDto(mixed $value): bool
    {
        return is_object($value)
            && ! $value instanceof Money
            && ! $value instanceof BigNumber
            && ! $value instanceof Date
            && ! $value instanceof BackedEnum
            && ! $value instanceof RawCode;
    }

    private function message(ValidationContext $context, Severity $severity, string $code, string $fallback, string $path, ?string $bt): ValidationMessage
    {
        return Messages::make(
            context: $context,
            severity: $severity,
            code: $code,
            level: ValidationLevel::L2,
            fallback: $fallback,
            replace: ['field' => $path, 'bt' => $bt ?? '-', 'profile' => $context->profile->name()],
            bt: $bt,
            path: $path,
        );
    }
}
