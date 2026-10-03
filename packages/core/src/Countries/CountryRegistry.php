<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Installed country packs.
 */
final class CountryRegistry
{
    /** @var array<string, CountryPack> */
    private array $packs = [];

    /**
     * @param  iterable<CountryPack>  $packs
     */
    public function __construct(iterable $packs = [])
    {
        foreach ($packs as $pack) {
            $this->register($pack);
        }
    }

    public function register(CountryPack $pack): self
    {
        $this->packs[strtoupper($pack->code())] = $pack;

        return $this;
    }

    public function has(string $code): bool
    {
        return isset($this->packs[strtoupper($code)]);
    }

    public function get(string $code): ?CountryPack
    {
        return $this->packs[strtoupper($code)] ?? null;
    }

    /**
     * Returns the installed pack or a generic pack for the country.
     */
    public function getOrGeneric(string $code): CountryPack
    {
        return $this->get($code) ?? new CountryGeneric(strtoupper($code));
    }

    /**
     * @return array<string, CountryPack>
     */
    public function all(): array
    {
        return $this->packs;
    }
}
