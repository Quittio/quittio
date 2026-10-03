<?php

declare(strict_types=1);

namespace Quittio\Laravel\Support;

/**
 * Finds country packs declared by Composer packages and by the root project
 * (composer.json: extra.quittio.country-packs).
 */
final class CountryPackDiscovery
{
    /**
     * @return list<class-string>
     */
    public static function discover(string $vendorPath): array
    {
        $packages = [];
        $installed = self::json(rtrim($vendorPath, '/').'/composer/installed.json');
        if (is_array($installed['packages'] ?? null)) {
            $packages = $installed['packages'];
        } elseif (array_is_list($installed)) {
            $packages = $installed;
        }
        $packages[] = self::json(dirname(rtrim($vendorPath, '/')).'/composer.json');

        $classes = [];
        foreach ($packages as $package) {
            $extra = is_array($package) && is_array($package['extra'] ?? null) ? $package['extra'] : [];
            $quittio = is_array($extra['quittio'] ?? null) ? $extra['quittio'] : [];
            $packs = $quittio['country-packs'] ?? [];
            foreach (is_array($packs) ? $packs : [] as $class) {
                if (is_string($class) && class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }

        return array_values(array_unique($classes));
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
