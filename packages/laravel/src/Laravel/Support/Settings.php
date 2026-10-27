<?php

declare(strict_types=1);

namespace Quittio\Laravel\Support;

use Quittio\Core\Exceptions\ConfigurationException;

/**
 * Typed read access to a configuration array ("quittio" by default) with dot notation.
 */
final readonly class Settings
{
    /**
     * @param  array<array-key, mixed>  $values
     * @param  string  $path  configuration key of $values, used in error messages
     */
    public function __construct(
        private array $values,
        private string $path = 'quittio',
    ) {}

    /**
     * The value at $key in dot notation (e.g. "seller.address.city"), or $default when a segment is
     * missing.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * The string at $key; $default when it is missing, null or empty. Throws a
     * ConfigurationException for another type.
     */
    public function string(string $key, string $default): string
    {
        return $this->nullableString($key) ?? $default;
    }

    /**
     * The string at $key; null when it is missing, null or empty. Throws a ConfigurationException
     * for another type.
     */
    public function nullableString(string $key): ?string
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new ConfigurationException(sprintf('%s.%s must be a string.', $this->path, $key));
        }

        return $value;
    }

    /**
     * The boolean at $key; $default when it is missing or null. Throws a ConfigurationException for
     * another type.
     */
    public function bool(string $key, bool $default): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        if (! is_bool($value)) {
            throw new ConfigurationException(sprintf('%s.%s must be a boolean.', $this->path, $key));
        }

        return $value;
    }

    /**
     * The integer at $key; $default when it is missing or null. Throws a ConfigurationException for
     * another type.
     */
    public function int(string $key, int $default): int
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        if (! is_int($value)) {
            throw new ConfigurationException(sprintf('%s.%s must be an integer.', $this->path, $key));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->get($key, []);
        if (! is_array($value)) {
            throw new ConfigurationException(sprintf('%s.%s must be an array.', $this->path, $key));
        }

        $result = [];
        foreach ($value as $k => $v) {
            $result[(string) $k] = $v;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        return array_values(array_filter($this->array($key), is_string(...)));
    }

    /**
     * The array at $key as settings of their own, whose error messages name the full key; empty
     * when the key is missing. Throws a ConfigurationException when the value is no array.
     */
    public function section(string $key): self
    {
        return new self($this->array($key), $this->path.'.'.$key);
    }
}
