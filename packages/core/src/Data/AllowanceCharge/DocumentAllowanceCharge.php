<?php

declare(strict_types=1);

namespace Quittio\Core\Data\AllowanceCharge;

use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use ReflectionClass;

/**
 * Common structure of document level allowances (BG-20) and charges (BG-21).
 *
 * The exemption reason and code are package-internal inputs like on LineVat: EN 16931
 * carries them on the VAT breakdown (BT-120, BT-121). The calculator uses them when the
 * VAT group of the entry has no invoice lines that provide a reason.
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
        public ?string $exemptionReason = null,
        public VatExemptionReasonCode|RawCode|null $exemptionReasonCode = null,
    ) {}

    /**
     * Whether this is a charge (BG-21) rather than an allowance (BG-20).
     */
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

    /**
     * The reason code (BT-98 or BT-105) as plain string, also for a RawCode; null without code.
     */
    public function reasonCodeValue(): ?string
    {
        return $this->reasonCode === null ? null : (string) $this->reasonCode->value;
    }
}
