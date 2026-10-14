<?php

declare(strict_types=1);

namespace Quittio\Core\Tax;

use Brick\Math\BigDecimal;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Support\Decimal;

/**
 * Ad-hoc tax preset, e.g. for an exemption with a specific legal reference.
 */
final readonly class CustomTaxPreset implements TaxPreset
{
    /**
     * @param  array<string, string>  $reasons  exemption reason text per locale
     */
    public function __construct(
        private string $key,
        private VatCategory $category,
        private ?BigDecimal $rate = null,
        private VatExemptionReasonCode|RawCode|null $code = null,
        private array $reasons = [],
    ) {}

    /**
     * @param  array<string, string>|string|null  $reason
     */
    public static function make(
        string $key,
        VatCategory $category,
        BigDecimal|int|float|string|null $rate = null,
        VatExemptionReasonCode|RawCode|string|null $code = null,
        array|string|null $reason = null,
    ): self {
        if (is_string($code)) {
            $code = VatExemptionReasonCode::tryFrom($code) ?? new RawCode($code);
        }

        return new self(
            $key,
            $category,
            $rate === null ? null : Decimal::of($rate),
            $code,
            is_string($reason) ? ['en' => $reason] : ($reason ?? []),
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function category(): VatCategory
    {
        return $this->category;
    }

    public function rate(): ?BigDecimal
    {
        return $this->rate;
    }

    public function exemptionReasonCode(): VatExemptionReasonCode|RawCode|null
    {
        return $this->code;
    }

    public function exemptionReason(?string $locale = null): ?string
    {
        if ($this->reasons === []) {
            return null;
        }

        return $this->reasons[$locale ?? 'en'] ?? $this->reasons['en'] ?? array_values($this->reasons)[0] ?? null;
    }
}
