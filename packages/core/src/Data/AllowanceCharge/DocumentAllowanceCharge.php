<?php

declare(strict_types=1);

namespace Quittio\Core\Data\AllowanceCharge;

use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use InvalidArgumentException;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\VatCategory;
use ReflectionClass;

/**
 * Common structure of document level allowances (BG-20) and charges (BG-21).
 */
abstract readonly class DocumentAllowanceCharge
{
    public function __construct(
        public ?Money $amount,
        public VatCategory $vatCategory,
        public ?BigDecimal $vatRate = null,
        public ?Money $baseAmount = null,
        public ?BigDecimal $percent = null,
        public ?string $reason = null,
        public BackedEnum|RawCode|null $reasonCode = null,
    ) {}

    abstract public function isCharge(): bool;

    /**
     * Returns a copy with the given properties replaced.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): static
    {
        $arguments = get_object_vars($this);
        foreach ($changes as $property => $value) {
            if (! array_key_exists($property, $arguments)) {
                throw new InvalidArgumentException(sprintf('Unknown property "%s" on %s.', $property, static::class));
            }
            $arguments[$property] = $value;
        }

        return (new ReflectionClass(static::class))->newInstanceArgs($arguments);
    }

    public function reasonCodeValue(): ?string
    {
        return $this->reasonCode === null ? null : (string) $this->reasonCode->value;
    }
}
