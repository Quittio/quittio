<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use Closure;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationLevel;

/**
 * A rule defined by a closure. The closure receives the invoice and a reporter.
 *
 * $fallback is the English message used when the message catalog has no entry.
 */
final readonly class CallbackRule implements Rule
{
    /**
     * @param  Closure(InvoiceData, RuleReporter): void  $check
     */
    public function __construct(
        private string $code,
        private ValidationLevel $level,
        private string $fallback,
        private Closure $check,
        private string $namespace = 'quittio',
    ) {}

    /**
     * @param  Closure(InvoiceData, RuleReporter): void  $check
     */
    public static function l3(string $code, string $fallback, Closure $check, string $namespace = 'quittio'): self
    {
        return new self($code, ValidationLevel::L3, $fallback, $check, $namespace);
    }

    /**
     * @param  Closure(InvoiceData, RuleReporter): void  $check
     */
    public static function l7(string $code, string $fallback, Closure $check, string $namespace = 'quittio'): self
    {
        return new self($code, ValidationLevel::L7, $fallback, $check, $namespace);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function level(): ValidationLevel
    {
        return $this->level;
    }

    public function fallbackMessage(): string
    {
        return $this->fallback;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function check(InvoiceData $invoice, ValidationContext $context): iterable
    {
        $reporter = new RuleReporter($this->code, $this->level, $context, $this->namespace, $this->fallback);
        ($this->check)($invoice, $reporter);

        return $reporter->messages();
    }
}
