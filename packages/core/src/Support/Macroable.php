<?php

declare(strict_types=1);

namespace Quittio\Core\Support;

use BadMethodCallException;
use Closure;

/**
 * Lightweight macro support without a dependency on Laravel.
 * Country packs use it to add helpers such as smallAmount() to the invoice builder.
 */
trait Macroable
{
    /** @var array<string, callable> */
    protected static array $macros = [];

    /**
     * @param-closure-this static $macro
     */
    public static function macro(string $name, callable $macro): void
    {
        static::$macros[$name] = $macro;
    }

    /**
     * Whether a macro with this name is registered on the class.
     */
    public static function hasMacro(string $name): bool
    {
        return isset(static::$macros[$name]);
    }

    /**
     * Removes every macro registered on the class, e.g. between tests.
     */
    public static function flushMacros(): void
    {
        static::$macros = [];
    }

    /**
     * @param  array<mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (! static::hasMacro($method)) {
            throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
        }

        $macro = static::$macros[$method];
        if ($macro instanceof Closure) {
            // Scope of the class that uses the trait, so that macros reach its private
            // properties also on subclasses (e.g. the RenderableInvoiceBuilder of Laravel).
            $macro = $macro->bindTo($this, self::class) ?? $macro;
        }

        return $macro(...$parameters);
    }
}
