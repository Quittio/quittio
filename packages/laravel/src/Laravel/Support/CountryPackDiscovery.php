<?php

declare(strict_types=1);

namespace Quittio\Laravel\Support;

/**
 * Finds country packs declared by Composer packages and by the root project
 * (composer.json: extra.quittio.country-packs).
 *
 * Reading vendor/composer/installed.json takes milliseconds, so the result is kept per process
 * and, with a cache file, across requests. Both are keyed on the modification times of
 * installed.json and composer.json, which Composer rewrites on every install and update.
 *
 * @internal
 */
final class CountryPackDiscovery
{
    /** @var array<string, array{key: list<int|false>, classes: list<string>}> */
    private static array $memo = [];

    /**
     * @param  string|null  $cacheFile  PHP file that keeps the result, e.g. bootstrap/cache/quittio-countries.php
     * @return list<class-string>
     */
    public static function discover(string $vendorPath, ?string $cacheFile = null): array
    {
        $installed = rtrim($vendorPath, '/').'/composer/installed.json';
        $root = dirname(rtrim($vendorPath, '/')).'/composer.json';
        clearstatcache(true, $installed);
        clearstatcache(true, $root);
        $key = [is_file($installed) ? filemtime($installed) : false, is_file($root) ? filemtime($root) : false];

        $classes = (self::$memo[$vendorPath]['key'] ?? null) === $key ? self::$memo[$vendorPath]['classes'] : null;
        $classes ??= $cacheFile === null ? null : self::cached($cacheFile, $key);
        if ($classes === null) {
            $classes = self::read($installed, $root);
            if ($cacheFile !== null) {
                self::store($cacheFile, $key, $classes);
            }
        }
        self::$memo[$vendorPath] = ['key' => $key, 'classes' => $classes];

        return array_values(array_filter($classes, class_exists(...)));
    }

    /**
     * Forgets the result kept for this process.
     */
    public static function flush(): void
    {
        self::$memo = [];
    }

    /**
     * @return list<string>
     */
    private static function read(string $installed, string $root): array
    {
        $packages = [];
        $data = self::json($installed);
        if (is_array($data['packages'] ?? null)) {
            $packages = $data['packages'];
        } elseif (array_is_list($data)) {
            $packages = $data;
        }
        $packages[] = self::json($root);

        $classes = [];
        foreach ($packages as $package) {
            $extra = is_array($package) && is_array($package['extra'] ?? null) ? $package['extra'] : [];
            $quittio = is_array($extra['quittio'] ?? null) ? $extra['quittio'] : [];
            $packs = $quittio['country-packs'] ?? [];
            foreach (is_array($packs) ? $packs : [] as $class) {
                if (is_string($class)) {
                    $classes[] = $class;
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param  list<int|false>  $key
     * @return list<string>|null
     */
    private static function cached(string $file, array $key): ?array
    {
        if (! is_file($file)) {
            return null;
        }
        $cached = require $file;
        if (! is_array($cached) || ($cached['key'] ?? null) !== $key || ! is_array($cached['classes'] ?? null)) {
            return null;
        }

        return array_values(array_filter($cached['classes'], is_string(...)));
    }

    /**
     * Writes the cache file atomically; a directory that is not writable only costs the cache.
     *
     * @param  list<int|false>  $key
     * @param  list<string>  $classes
     */
    private static function store(string $file, array $key, array $classes): void
    {
        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        $contents = '<?php return '.var_export(['key' => $key, 'classes' => $classes], true).';'.PHP_EOL;

        set_error_handler(static fn (): bool => true);
        try {
            if (is_dir(dirname($file)) && file_put_contents($temporary, $contents) !== false && ! rename($temporary, $file)) {
                unlink($temporary);
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }
}
