<?php

declare(strict_types=1);

namespace Quittio\Laravel\Support;

use Quittio\Core\Exceptions\ConfigurationException;

/**
 * Typed read access to the "quittio" configuration array with dot notation.
 */
final readonly class Settings
{
    /**
     * @param  array<array-key, mixed>  $values
     */
    public function __construct(
        private array $values,
    ) {}

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

    public function string(string $key, string $default): string
    {
        return $this->nullableString($key) ?? $default;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new ConfigurationException(sprintf('quittio.%s must be a string.', $key));
        }

        return $value;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        if (! is_bool($value)) {
            throw new ConfigurationException(sprintf('quittio.%s must be a boolean.', $key));
        }

        return $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        if (! is_int($value)) {
            throw new ConfigurationException(sprintf('quittio.%s must be an integer.', $key));
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
            throw new ConfigurationException(sprintf('quittio.%s must be an array.', $key));
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

    public function section(string $key): self
    {
        return new self($this->array($key));
    }
}
