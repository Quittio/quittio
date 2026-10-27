<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use Quittio\Core\Validation\Messages;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;

/**
 * Collects the findings of one rule.
 */
final class RuleReporter
{
    /** @var list<ValidationMessage> */
    private array $messages = [];

    public function __construct(
        private readonly string $code,
        private readonly ValidationLevel $level,
        private readonly ValidationContext $context,
        private readonly string $namespace,
        private readonly string $fallback,
    ) {}

    /**
     * @param  array<string, string|int>  $replace
     */
    public function error(?string $bt = null, ?string $path = null, array $replace = []): void
    {
        $this->add(Severity::Error, $bt, $path, $replace);
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    public function warning(?string $bt = null, ?string $path = null, array $replace = []): void
    {
        $this->add(Severity::Warning, $bt, $path, $replace);
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    public function info(?string $bt = null, ?string $path = null, array $replace = []): void
    {
        $this->add(Severity::Info, $bt, $path, $replace);
    }

    /**
     * Context of the validation run, e.g. for the profile, the country packs and the options.
     */
    public function context(): ValidationContext
    {
        return $this->context;
    }

    /**
     * @return list<ValidationMessage>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function add(Severity $severity, ?string $bt, ?string $path, array $replace): void
    {
        $this->messages[] = Messages::make(
            context: $this->context,
            severity: $severity,
            code: $this->code,
            level: $this->level,
            fallback: $this->fallback,
            replace: $replace,
            bt: $bt,
            path: $path,
            namespace: $this->namespace,
        );
    }
}
