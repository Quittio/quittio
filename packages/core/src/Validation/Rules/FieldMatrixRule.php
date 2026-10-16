<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use BackedEnum;
use Brick\Math\BigNumber;
use Brick\Money\Money;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Mapping\FieldMatrix;
use Quittio\Core\Profiles\Capabilities\SupportsExtensionFields;
use Quittio\Core\Profiles\Capabilities\SupportsSubLines;
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
    /** The codes of the findings this rule reports. */
    public const array CODES = ['QUITTIO-PROFILE-REQUIRED', 'QUITTIO-PROFILE-NOT-ALLOWED', 'QUITTIO-PROFILE-EXTENDED-ONLY', 'QUITTIO-PROFILE-XRECHNUNG-EXTENSION-ONLY', 'QUITTIO-PROFILE-SUBLINES'];

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
        $this->walk($invoice, '', null, null, false, $context, $messages);

        $hasSubLines = array_filter($invoice->lines, static fn ($line): bool => $line->subLines !== []) !== [];
        if ($hasSubLines && $context->profile->level() !== ProfileLevel::Extended && ! $context->profile instanceof SupportsSubLines) {
            $messages[] = $this->message($context, Severity::Error, 'QUITTIO-PROFILE-SUBLINES', 'Sub lines are supported only in the profiles EXTENDED and XRechnung extension, not in :profile.', 'lines', null);
        }

        return $messages;
    }

    /**
     * A field is not part of a profile if its level, raised to the level of its parent field,
     * is above the profile's level (see FieldMatrix::level()). The finding of a field covers its
     * parts: below a field that is EXTENDED or XRechnung extension only the walk stops, below a
     * field that was reported as not allowed its parts are not reported as not allowed again
     * (but parts that are EXTENDED only still are).
     *
     * @param  string|null  $parentTerm  business term of the parent field (BT-55 instead of BT-40 below BG-8)
     * @param  ProfileLevel|null  $parentLevel  effective level of the parent field
     * @param  bool  $parentReported  the parent field was reported as not part of the profile
     * @param  list<ValidationMessage>  $messages
     */
    private function walk(object $dto, string $path, ?string $parentTerm, ?ProfileLevel $parentLevel, bool $parentReported, ValidationContext $context, array &$messages): void
    {
        $profile = $context->profile;
        $values = get_object_vars($dto);

        foreach (FieldMatrix::fields($dto::class) as $name => $field) {
            $value = $values[$name] ?? null;
            $childPath = $path === '' ? $name : $path.'.'.$name;
            $bt = FieldMatrix::businessTerm($dto::class, $name, $parentTerm);
            $level = FieldMatrix::level($dto::class, $name, $parentLevel);
            $present = ! ($value === null || $value === '' || $value === []);
            $reported = $parentReported;

            if ($present) {
                if ($field->extendedOnly && $profile->level() !== ProfileLevel::Extended) {
                    $messages[] = $this->message($context, Severity::Error, 'QUITTIO-PROFILE-EXTENDED-ONLY', 'The field :field is available only in profile EXTENDED, not in :profile.', $childPath, $bt);

                    continue;
                }
                if ($field->xrechnungExtensionOnly && ! ($profile instanceof SupportsExtensionFields && in_array('xrechnung', $profile->extensions(), true))) {
                    $messages[] = $this->message($context, Severity::Error, 'QUITTIO-PROFILE-XRECHNUNG-EXTENSION-ONLY', 'The field :field is available only in the XRechnung extension, not in :profile.', $childPath, $bt);

                    continue;
                }
                if (! $parentReported && ! $field->pdfOnly && ! $field->internal && ! $field->computed && $bt !== null && ! $profile->level()->includes($level)) {
                    $messages[] = $this->message($context, Severity::Warning, 'QUITTIO-PROFILE-NOT-ALLOWED', 'The field :field (:bt) is not part of profile :profile and will not be written to the XML.', $childPath, $bt);
                    $reported = true;
                }
            } elseif ($bt !== null && $this->isRequired($bt, $field->requiredIn, $context)) {
                $messages[] = $this->message($context, Severity::Error, 'QUITTIO-PROFILE-REQUIRED', 'The field :field (:bt) is mandatory in profile :profile.', $childPath, $bt);
            }

            if (! $present || $field->pdfOnly) {
                continue;
            }

            foreach (is_array($value) ? $value : [$value] as $index => $child) {
                if ($this->isDto($child)) {
                    $this->walk($child, is_array($value) ? $childPath.'['.$index.']' : $childPath, $bt, $level, $reported, $context, $messages);
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
