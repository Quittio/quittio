<?php

declare(strict_types=1);

namespace Quittio\Core\View;

/**
 * Records which view model fields a template read (for the mandatory field guard).
 *
 * Only leaf values that are not null (scalars, formatted values) are recorded, so counting
 * or iterating a list does not count as printing it. Paths may contain "[*]": for
 * {@see self::accessed()} it matches any list index, {@see self::expand()} lists every index.
 *
 * Aliases map one view model node onto another, e.g. "issuer" onto "seller": a template
 * that prints $invoice->issuer->address has printed the seller's address.
 */
final class AccessLog
{
    /** @var array<string, true> */
    private array $paths = [];

    /** @var array<string|int, mixed>|null */
    private ?array $data = null;

    /** @var array<string, string> */
    private array $aliases = [];

    /**
     * The view model data the paths refer to, for {@see self::expand()}.
     *
     * @param  array<string|int, mixed>  $data
     * @param  array<string, string>  $aliases  path prefix => path prefix it stands for
     */
    public function bind(array $data, array $aliases = []): void
    {
        $this->data = $data;
        $this->aliases = $aliases;
    }

    public function hasData(): bool
    {
        return $this->data !== null;
    }

    public function record(string $path): void
    {
        if (isset($this->paths[$path])) {
            return;
        }

        $this->paths[$path] = true;
        foreach ($this->aliases as $from => $to) {
            if ($path === $from || str_starts_with($path, $from.'.') || str_starts_with($path, $from.'[')) {
                $this->record($to.substr($path, strlen($from)));
            }
        }
    }

    /**
     * Whether $path or any field below it was read.
     */
    public function accessed(string $path): bool
    {
        if (isset($this->paths[$path])) {
            return true;
        }

        if (str_contains($path, '[*]')) {
            $pattern = '/^'.str_replace('\\[\\*\\]', '\\[\\d+\\]', preg_quote($path, '/')).'(?:$|[.\\[])/';
            foreach (array_keys($this->paths) as $accessed) {
                if (preg_match($pattern, $accessed) === 1) {
                    return true;
                }
            }

            return false;
        }

        foreach (array_keys($this->paths) as $accessed) {
            if (str_starts_with($accessed, $path.'.') || str_starts_with($accessed, $path.'[')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The concrete paths of $pattern that hold a value (not null, not an empty list) in the
     * bound data; "[*]" expands to every index, e.g. "vatRows[*].amount" to
     * ["vatRows[0].amount", "vatRows[1].amount"].
     *
     * @return list<string>
     */
    public function expand(string $pattern): array
    {
        if ($this->data === null) {
            return [$pattern];
        }

        preg_match_all('/([^.\[\]]+)|\[(\*|\d+)\]/', $pattern, $matches, PREG_SET_ORDER);
        $tokens = [];
        foreach ($matches as $match) {
            $tokens[] = isset($match[2]) && $match[2] !== '' ? ['index', $match[2]] : ['key', $match[1] ?? ''];
        }

        $paths = [];
        self::walk($this->data, $tokens, 0, '', $paths);

        return $paths;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->paths);
    }

    public function reset(): void
    {
        $this->paths = [];
    }

    /**
     * @param  list<array{string, string}>  $tokens
     * @param  list<string>  $paths
     */
    private static function walk(mixed $value, array $tokens, int $position, string $path, array &$paths): void
    {
        if ($position === count($tokens)) {
            if (self::hasValue($value)) {
                $paths[] = $path;
            }

            return;
        }
        if (! is_array($value)) {
            return;
        }

        [$type, $name] = $tokens[$position];
        if ($type === 'key') {
            if (array_key_exists($name, $value)) {
                self::walk($value[$name], $tokens, $position + 1, $path === '' ? $name : $path.'.'.$name, $paths);
            }

            return;
        }

        $indexes = $name === '*' ? array_filter(array_keys($value), is_int(...)) : [(int) $name];
        foreach ($indexes as $index) {
            if (array_key_exists($index, $value)) {
                self::walk($value[$index], $tokens, $position + 1, $path.'['.$index.']', $paths);
            }
        }
    }

    private static function hasValue(mixed $value): bool
    {
        if (! is_array($value)) {
            return $value !== null;
        }
        foreach ($value as $item) {
            if (self::hasValue($item)) {
                return true;
            }
        }

        return false;
    }
}
