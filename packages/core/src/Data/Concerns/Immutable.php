<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Concerns;

use Quittio\Core\Exceptions\InvalidArgumentException;
use ReflectionClass;

/**
 * Generic copy-on-write helper for readonly DTOs whose properties are all promoted constructor parameters.
 */
trait Immutable
{
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
}
