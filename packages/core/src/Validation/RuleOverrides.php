<?php

declare(strict_types=1);

namespace Quittio\Core\Validation;

use Quittio\Core\Exceptions\ConfigurationException;

/**
 * Changes to the PHP rules by rule code: rules switched off, findings with another severity
 * and rules replaced by own implementations. Codes match exactly or, with a trailing "*", by
 * prefix (e.g. "DE-USTG-*"). The overrides apply to the PHP rules only; the findings of the
 * XSD schema, KoSIT and veraPDF are never changed.
 *
 * The rules of EN 16931 (BR-*) and Peppol (PEPPOL-*) are official: switching them off,
 * changing their severity or replacing them needs $allowOfficial, because an invoice that
 * breaks them is rejected by the receiver.
 */
final readonly class RuleOverrides
{
    private const array OFFICIAL_PREFIXES = ['BR-', 'PEPPOL-'];

    /**
     * @param  list<string>  $disabled  codes or prefixes ending with "*" whose rules and findings are dropped
     * @param  array<string, Severity>  $severities  severity of the findings per code or prefix
     * @param  array<string, Rule>  $replacements  rules that replace the rule with the code of the key
     *
     * @throws ConfigurationException when an official rule is changed without $allowOfficial or a pattern is empty
     */
    public function __construct(
        public array $disabled = [],
        public array $severities = [],
        public array $replacements = [],
        public bool $allowOfficial = false,
    ) {
        foreach ([...$disabled, ...array_keys($severities), ...array_keys($replacements)] as $pattern) {
            $pattern = (string) $pattern;
            if (trim($pattern, '*') === '' && $pattern !== '*') {
                throw new ConfigurationException('An empty rule code cannot be overridden.');
            }
            if (! $allowOfficial && self::coversOfficial($pattern)) {
                throw new ConfigurationException(sprintf('The rule code "%s" covers official EN 16931 or Peppol rules; set allow_official to change them.', $pattern));
            }
        }
    }

    /**
     * From the configuration keys disabled (list of codes), severity (code => error, warning or
     * info) and allow_official.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws ConfigurationException for an unknown severity or an official rule without allow_official
     */
    public static function fromArray(array $config): self
    {
        $disabled = [];
        foreach (is_array($config['disabled'] ?? null) ? $config['disabled'] : [] as $code) {
            if (is_string($code) && $code !== '') {
                $disabled[] = $code;
            }
        }

        $severities = [];
        foreach (is_array($config['severity'] ?? null) ? $config['severity'] : [] as $code => $severity) {
            $severities[(string) $code] = $severity instanceof Severity ? $severity : (Severity::tryFrom(is_string($severity) ? strtolower($severity) : '')
                ?? throw new ConfigurationException(sprintf('Unknown severity for rule "%s"; use error, warning or info.', $code)));
        }

        return new self($disabled, $severities, allowOfficial: ($config['allow_official'] ?? false) === true);
    }

    /**
     * These overrides with those of $other on top: $other wins for the same code.
     */
    public function merge(self $other): self
    {
        return new self(
            array_values(array_unique([...$this->disabled, ...$other->disabled])),
            [...$this->severities, ...$other->severities],
            [...$this->replacements, ...$other->replacements],
            $this->allowOfficial || $other->allowOfficial,
        );
    }

    /**
     * Whether nothing is disabled, re-rated or replaced.
     */
    public function isEmpty(): bool
    {
        return $this->disabled === [] && $this->severities === [] && $this->replacements === [];
    }

    /**
     * Whether findings with this code are dropped.
     */
    public function isDisabled(string $code): bool
    {
        foreach ($this->disabled as $pattern) {
            if (self::matches($pattern, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every pattern of these overrides, e.g. to check them against the known rule codes.
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        return array_values(array_unique([...$this->disabled, ...array_map(strval(...), array_keys($this->severities)), ...array_map(strval(...), array_keys($this->replacements))]));
    }

    /**
     * The rules without the disabled ones, replaced rules exchanged in place.
     *
     * @param  list<Rule>  $rules
     * @return list<Rule>
     */
    public function apply(array $rules): array
    {
        if ($this->isEmpty()) {
            return $rules;
        }

        $result = [];
        foreach ($rules as $rule) {
            $rule = $this->replacements[$rule->code()] ?? $rule;
            if (! $this->isDisabled($rule->code())) {
                $result[] = $rule;
            }
        }

        return $result;
    }

    /**
     * The findings without those of disabled codes, with the configured severities. Rules may
     * report other codes than their own (e.g. QUITTIO-PROFILE-REQUIRED), so the findings are
     * filtered as well.
     *
     * @param  list<ValidationMessage>  $messages
     * @return list<ValidationMessage>
     */
    public function applyToMessages(array $messages): array
    {
        if ($this->disabled === [] && $this->severities === []) {
            return $messages;
        }

        $result = [];
        foreach ($messages as $message) {
            if ($this->isDisabled($message->code)) {
                continue;
            }
            $severity = $this->severity($message->code);
            $result[] = $severity === null || $severity === $message->severity ? $message : new ValidationMessage(
                severity: $severity,
                code: $message->code,
                message: $message->message,
                level: $message->level,
                bt: $message->bt,
                path: $message->path,
                hint: $message->hint,
                xpath: $message->xpath,
            );
        }

        return $result;
    }

    /**
     * Whether a pattern matches a code: exactly, or by prefix with a trailing "*".
     */
    public static function matches(string $pattern, string $code): bool
    {
        return str_ends_with($pattern, '*') ? str_starts_with($code, substr($pattern, 0, -1)) : $pattern === $code;
    }

    /**
     * The severity of the most specific pattern that matches: an exact code before the
     * longest prefix.
     */
    private function severity(string $code): ?Severity
    {
        if (isset($this->severities[$code])) {
            return $this->severities[$code];
        }

        $best = null;
        $length = -1;
        foreach ($this->severities as $pattern => $severity) {
            $pattern = (string) $pattern;
            if (str_ends_with($pattern, '*') && self::matches($pattern, $code) && strlen($pattern) > $length) {
                $best = $severity;
                $length = strlen($pattern);
            }
        }

        return $best;
    }

    private static function coversOfficial(string $pattern): bool
    {
        $prefix = str_ends_with($pattern, '*') ? substr($pattern, 0, -1) : null;
        foreach (self::OFFICIAL_PREFIXES as $official) {
            if ($prefix === null ? str_starts_with($pattern, $official) : (str_starts_with($prefix, $official) || str_starts_with($official, $prefix))) {
                return true;
            }
        }

        return false;
    }
}
