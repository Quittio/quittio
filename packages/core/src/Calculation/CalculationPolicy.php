<?php

declare(strict_types=1);

namespace Quittio\Core\Calculation;

use Quittio\Core\Exceptions\ConfigurationException;

/**
 * Decisions of the calculator that are not fixed by EN 16931. The policy is configuration of
 * the run and not stored with the invoice: the same data calculated under another policy can
 * give other amounts.
 */
final readonly class CalculationPolicy
{
    public function __construct(
        public DeclaredAmountsMode $declaredAmounts = DeclaredAmountsMode::Keep,
    ) {}

    /**
     * From the configuration key declared_amounts (keep, recalculate or reject).
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws ConfigurationException for an unknown mode
     */
    public static function fromArray(array $config): self
    {
        $mode = $config['declared_amounts'] ?? null;
        if ($mode === null || $mode === '') {
            return new self;
        }
        if ($mode instanceof DeclaredAmountsMode) {
            return new self($mode);
        }

        return new self(DeclaredAmountsMode::tryFrom(is_string($mode) ? strtolower($mode) : '')
            ?? throw new ConfigurationException(sprintf('Unknown declared amounts mode "%s"; use keep, recalculate or reject.', is_scalar($mode) ? $mode : get_debug_type($mode))));
    }
}
