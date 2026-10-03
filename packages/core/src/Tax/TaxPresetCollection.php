<?php

declare(strict_types=1);

namespace Quittio\Core\Tax;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<string, TaxPreset>
 */
final class TaxPresetCollection implements Countable, IteratorAggregate
{
    /** @var array<string, TaxPreset> */
    private array $presets = [];

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

    public function has(string $key): bool
    {
        return isset($this->presets[$key]);
    }

    public function get(string $key): TaxPreset
    {
        return $this->presets[$key] ?? throw new InvalidArgumentException(sprintf('Unknown tax preset "%s".', $key));
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
