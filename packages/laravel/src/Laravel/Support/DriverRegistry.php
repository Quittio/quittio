<?php

declare(strict_types=1);

namespace Quittio\Laravel\Support;

use Closure;
use Illuminate\Contracts\Container\Container;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Exceptions\InvalidArgumentException;

/**
 * The drivers registered with Quittio::extend(): factories per type and name.
 *
 * @internal
 */
final class DriverRegistry
{
    public const array TYPES = ['renderer', 'validator', 'serializer', 'format_resolver', 'rule'];

    /** @var array<string, array<string, Closure(Container, array<string, mixed>): object>> */
    private array $factories = ['renderer' => [], 'validator' => [], 'serializer' => [], 'format_resolver' => [], 'rule' => []];

    /**
     * @param  Closure(Container, array<string, mixed>): object  $factory
     */
    public function extend(string $type, string $name, Closure $factory): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown extension type "%s". Known types: %s.', $type, implode(', ', self::TYPES)));
        }

        $this->factories[$type][$name] = $factory;
    }

    public function has(string $type, string $name): bool
    {
        return isset($this->factories[$type][$name]);
    }

    /**
     * @return list<string>
     */
    public function names(string $type): array
    {
        return array_map(strval(...), array_keys($this->factories[$type] ?? []));
    }

    /**
     * The driver of a registered factory, checked against the type the core expects.
     *
     * @template T of object
     *
     * @param  array<string, mixed>  $config
     * @param  class-string<T>  $expected
     * @return T
     */
    public function make(string $type, string $name, Container $container, array $config, string $expected): object
    {
        $factory = $this->factories[$type][$name] ?? throw new ConfigurationException(sprintf('Unknown %s "%s".', str_replace('_', ' ', $type), $name));
        $instance = $factory($container, $config);
        if (! $instance instanceof $expected) {
            throw new ConfigurationException(sprintf('The %s "%s" must return an instance of %s.', $type, $name, $expected));
        }

        return $instance;
    }
}
