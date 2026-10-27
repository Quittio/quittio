<?php

declare(strict_types=1);

namespace Quittio\Core\Validation;

use JsonSerializable;

/**
 * A single validation finding.
 */
final readonly class ValidationMessage implements JsonSerializable
{
    public function __construct(
        public Severity $severity,
        public string $code,
        public string $message,
        public ValidationLevel $level,
        public ?string $bt = null,
        public ?string $path = null,
        public ?string $hint = null,
        public ?string $xpath = null,
    ) {}

    /**
     * Whether the finding has severity error, i.e. makes the invoice invalid.
     */
    public function isError(): bool
    {
        return $this->severity === Severity::Error;
    }

    /**
     * @return array{severity: string, code: string, message: string, level: string, bt: ?string, path: ?string, hint: ?string, xpath: ?string}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'code' => $this->code,
            'message' => $this->message,
            'level' => $this->level->value,
            'bt' => $this->bt,
            'path' => $this->path,
            'hint' => $this->hint,
            'xpath' => $this->xpath,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
