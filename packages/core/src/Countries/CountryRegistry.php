<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

use Quittio\Core\Tax\TaxPreset;

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
     * The tax preset with this key from the presets of the installed packs, e.g. "de.standard_19".
     */
    public function taxPreset(string $key): ?TaxPreset
    {
        foreach ($this->packs as $pack) {
            $presets = $pack->taxPresets();
            if ($presets->has($key)) {
                return $presets->get($key);
            }
        }

        return null;
    }

    /**
     * @return array<string, CountryPack>
     */
    public function all(): array
    {
        return $this->packs;
    }
}
