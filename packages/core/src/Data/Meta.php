<?php

declare(strict_types=1);

namespace Quittio\Core\Data;

use Brick\Math\BigDecimal;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Support\Date;

/**
 * Package-internal metadata: locale, time zone, exchange rate, free data for templates and flags for country packs.
 */
final readonly class Meta
{
    use Immutable;

    /**
     * @param  array<string, mixed>  $custom
     * @param  list<string>  $flags
     */
    public function __construct(
        #[Q\Internal]
        public ?string $locale = null,
        #[Q\Internal]
        public ?string $timezone = null,
        #[Q\Internal]
        public ?BigDecimal $exchangeRate = null,
        #[Q\Internal]
        public ?Date $exchangeRateDate = null,
        #[Q\Internal]
        public array $custom = [],
        #[Q\Internal]
        public ?string $source = null,
        #[Q\Internal, Q\ListOf('string')]
        public array $flags = [],
    ) {}

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    public function withFlag(string $flag, bool $enabled = true): self
    {
        $flags = array_values(array_filter($this->flags, static fn (string $f): bool => $f !== $flag));
        if ($enabled) {
            $flags[] = $flag;
        }

        return $this->with(['flags' => $flags]);
    }
}
