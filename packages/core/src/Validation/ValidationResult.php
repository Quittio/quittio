<?php

declare(strict_types=1);

namespace Quittio\Core\Validation;

use JsonSerializable;

/**
 * Result of a validation run.
 */
final readonly class ValidationResult implements JsonSerializable
{
    /**
     * @param  list<ValidationMessage>  $messages
     * @param  list<ValidationLevel>  $levels  levels that were checked
     */
    public function __construct(
        public array $messages = [],
        public array $levels = [],
    ) {}

    /**
     * Whether the result holds no errors; warnings and infos do not count.
     */
    public function isValid(): bool
    {
        return $this->errors() === [];
    }

    /**
     * Whether the result holds at least one warning.
     */
    public function hasWarnings(): bool
    {
        return $this->warnings() !== [];
    }

    /**
     * @return list<ValidationMessage>
     */
    public function errors(): array
    {
        return $this->bySeverity(Severity::Error);
    }

    /**
     * @return list<ValidationMessage>
     */
    public function warnings(): array
    {
        return $this->bySeverity(Severity::Warning);
    }

    /**
     * @return list<ValidationMessage>
     */
    public function infos(): array
    {
        return $this->bySeverity(Severity::Info);
    }

    /**
     * @return list<ValidationMessage>
     */
    public function byLevel(ValidationLevel $level): array
    {
        return array_values(array_filter($this->messages, static fn (ValidationMessage $m): bool => $m->level === $level));
    }

    /**
     * Whether a finding with $code was reported, e.g. "BR-CO-10", whatever its severity.
     */
    public function has(string $code): bool
    {
        foreach ($this->messages as $message) {
            if ($message->code === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ValidationMessage>
     */
    private function bySeverity(Severity $severity): array
    {
        return array_values(array_filter($this->messages, static fn (ValidationMessage $m): bool => $m->severity === $severity));
    }

    /**
     * Returns a new result with the messages of both results, these first, and the levels of both
     * without duplicates.
     */
    public function merge(self $other): self
    {
        $levels = $this->levels;
        foreach ($other->levels as $level) {
            if (! in_array($level, $levels, true)) {
                $levels[] = $level;
            }
        }

        return new self([...$this->messages, ...$other->messages], $levels);
    }

    /**
     * @return array{valid: bool, levels: list<string>, messages: list<array<string, string|null>>}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'levels' => array_map(static fn (ValidationLevel $l): string => $l->value, $this->levels),
            'messages' => array_map(static fn (ValidationMessage $m): array => $m->toArray(), $this->messages),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Readable validation report as a self-contained HTML fragment.
     */
    public function toHtml(): string
    {
        $e = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $status = $this->isValid()
            ? '<p class="quittio-report-status quittio-valid">Valid'.($this->hasWarnings() ? ' with warnings' : '').'</p>'
            : '<p class="quittio-report-status quittio-invalid">Invalid</p>';

        if ($this->messages === []) {
            return '<div class="quittio-report">'.$status.'</div>';
        }

        $rows = '';
        foreach ($this->messages as $message) {
            $rows .= sprintf(
                '<tr class="quittio-%s"><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s%s</td></tr>',
                $e($message->severity->value),
                $e($message->severity->value),
                $e($message->level->value),
                $e($message->code),
                $e($message->bt),
                $e($message->message),
                $message->hint === null ? '' : '<br><small>'.$e($message->hint).'</small>',
            );
        }

        return '<div class="quittio-report">'.$status
            .'<table><thead><tr><th>Severity</th><th>Level</th><th>Code</th><th>BT</th><th>Message</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody></table></div>';
    }
}
