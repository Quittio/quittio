<?php

declare(strict_types=1);

namespace Quittio\Core\Validation;

/**
 * A validation driver checks one or more levels (PHP rules, XSD, KoSIT, veraPDF, …).
 */
interface ValidationDriver
{
    public function name(): string;

    /**
     * @return list<ValidationLevel>
     */
    public function levels(): array;

    /**
     * Whether the driver can run for the subject (e.g. XML drivers need generated XML).
     */
    public function supports(ValidationSubject $subject): bool;

    public function validate(ValidationSubject $subject): ValidationResult;
}
