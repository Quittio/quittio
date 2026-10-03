<?php

declare(strict_types=1);

namespace Quittio\Core\I18n;

/**
 * Translator backed by PHP array files: {path}/{locale}/{group}.php.
 *
 * Keys follow Laravel's convention "namespace::group.key". Paths are registered
 * per namespace; later paths override earlier ones. Missing keys fall back to the
 * fallback locale and finally to the key itself. Item keys may contain dots
 * (e.g. "de.standard_19"); a flat key wins over a nested lookup.
 */
final class ArrayTranslator implements Translator
{
    public const string CORE_NAMESPACE = 'quittio';

    /** @var array<string, list<string>> */
    private array $paths = [];

    /** @var array<string, array<mixed>> */
    private array $loaded = [];

    public function __construct(
        private string $locale = 'en',
        private readonly string $fallbackLocale = 'en',
    ) {
        $this->addPath(dirname(__DIR__, 2).'/resources/lang', self::CORE_NAMESPACE);
    }

    public function addPath(string $path, string $namespace = '*'): self
    {
        $this->paths[$namespace] ??= [];
        if (! in_array($path, $this->paths[$namespace], true)) {
            $this->paths[$namespace][] = $path;
            $this->loaded = [];
        }

        return $this;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $value = $this->lookup($key, $locale ?? $this->locale)
            ?? $this->lookup($key, $this->fallbackLocale)
            ?? $key;

        foreach ($replace as $name => $replacement) {
            $value = str_replace(':'.$name, (string) $replacement, $value);
        }

        return $value;
    }

    public function has(string $key, ?string $locale = null): bool
    {
        return $this->lookup($key, $locale ?? $this->locale) !== null;
    }

    private function lookup(string $key, string $locale): ?string
    {
        $namespace = '*';
        if (str_contains($key, '::')) {
            [$namespace, $key] = explode('::', $key, 2);
        }

        $parts = explode('.', $key, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$group, $item] = $parts;

        $lines = $this->load($namespace, $locale, $group);

        return $this->find($lines, $item);
    }

    /**
     * @param  array<mixed>  $lines
     */
    private function find(array $lines, string $item): ?string
    {
        if (array_key_exists($item, $lines)) {
            return is_string($lines[$item]) ? $lines[$item] : null;
        }

        // Try every split point, so that segments containing dots are found as well.
        $offset = 0;
        while (($position = strpos($item, '.', $offset)) !== false) {
            $head = substr($item, 0, $position);
            if (isset($lines[$head]) && is_array($lines[$head])) {
                $found = $this->find($lines[$head], substr($item, $position + 1));
                if ($found !== null) {
                    return $found;
                }
            }
            $offset = $position + 1;
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    private function load(string $namespace, string $locale, string $group): array
    {
        $cacheKey = $namespace.'|'.$locale.'|'.$group;
        if (isset($this->loaded[$cacheKey])) {
            return $this->loaded[$cacheKey];
        }

        $lines = [];
        foreach ($this->paths[$namespace] ?? [] as $path) {
            $file = $path.'/'.$locale.'/'.$group.'.php';
            if (is_file($file)) {
                $data = require $file;
                if (is_array($data)) {
                    $lines = array_replace_recursive($lines, $data);
                }
            }
        }

        return $this->loaded[$cacheKey] = $lines;
    }
}
