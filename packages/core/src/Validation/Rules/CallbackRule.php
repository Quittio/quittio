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

    /**
     * Validation level of the rule, as given to the constructor, l3() or l7().
     */
    public function level(): ValidationLevel
    {
        return $this->level;
    }

    /**
     * English message used when the message catalog has no entry for the code.
     */
    public function fallbackMessage(): string
    {
        return $this->fallback;
    }

    /**
     * Translation namespace of the message catalog the message is looked up in, e.g. "quittio" for
     * "quittio::rules.<code>.message".
     */
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
