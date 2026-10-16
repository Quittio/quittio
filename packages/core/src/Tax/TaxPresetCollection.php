<?php

declare(strict_types=1);

namespace Quittio\Core\Tax;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Traversable;

/**
 * @implements IteratorAggregate<string, TaxPreset>
 */
final class TaxPresetCollection implements Countable, IteratorAggregate
{
    /** @var array<string, TaxPreset> */
    private array $presets = [];

    /** @var array<string, TaxPreset> presets under a further key, e.g. a key of an earlier version */
    private array $aliases = [];

    /**
     * @param  iterable<TaxPreset>  $presets
     */
    public function __construct(iterable $presets = [])
    {
        foreach ($presets as $preset) {
            $this->add($preset);
        }
    }

    public function add(TaxPreset $preset): self
    {
        $this->presets[$preset->key()] = $preset;

        return $this;
    }

    /**
     * Makes a preset available under a further key, e.g. the key of an earlier version that stored
     * data still carries. has() and get() find it; iterating and count() list only the presets.
     */
    public function alias(string $key, TaxPreset $preset): self
    {
        $this->aliases[$key] = $preset;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->presets[$key]) || isset($this->aliases[$key]);
    }

    public function get(string $key): TaxPreset
    {
        return $this->presets[$key] ?? $this->aliases[$key] ?? throw new InvalidArgumentException(sprintf('Unknown tax preset "%s".', $key));
    }

    public function count(): int
    {
        return count($this->presets);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->presets);
    }
}
