<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Leading pack (seller side) and, for cross-border invoices, the buyer's pack.
 */
final readonly class ResolvedCountries
{
    public function __construct(
        public CountryPack $leading,
        public ?CountryPack $buyer = null,
    ) {}

    /**
     * Whether a buyer pack for another country than the leading pack is set.
     */
    public function isCrossBorder(): bool
    {
        return $this->buyer !== null && $this->buyer->code() !== $this->leading->code();
    }

    /**
     * @return list<CountryPack>
     */
    public function all(): array
    {
        return $this->isCrossBorder() && $this->buyer !== null ? [$this->leading, $this->buyer] : [$this->leading];
    }

    /**
     * Whether the leading pack is the generic pack (CountryGeneric), i.e. no pack is installed for
     * the seller's country.
     */
    public function isGeneric(): bool
    {
        return $this->leading instanceof CountryGeneric;
    }
}
