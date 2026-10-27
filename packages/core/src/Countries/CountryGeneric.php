<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Neutral pack for countries without an installed country pack:
 * EN 16931 rules only and international formatting. A warning is issued
 * when an invoice is validated with this pack.
 */
final class CountryGeneric extends AbstractCountryPack
{
    public function __construct(
        private readonly string $code = 'XX',
    ) {}

    public function code(): string
    {
        return $this->code;
    }

    /**
     * Always true: this pack stands in for a country without an installed pack.
     */
    public function isGeneric(): bool
    {
        return true;
    }
}
