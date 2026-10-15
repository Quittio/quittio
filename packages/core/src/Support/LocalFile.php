<?php

declare(strict_types=1);

namespace Quittio\Core\Support;

use RuntimeException;

/**
 * Writes rendered output to local files.
 *
 * @internal
 */
final class LocalFile
{
    /**
     * Writes $contents to $path, or into $path with $filename when $path is an existing directory
     * or ends in "/". Missing directories are created.
     *
     * @return string the written path
     *
     * @throws RuntimeException when the directory cannot be created or the file cannot be written
     */
    public static function save(string $path, string $filename, string $contents): string
    {
        if (is_dir($path) || str_ends_with($path, '/')) {
            $path = rtrim($path, '/').'/'.$filename;
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! self::quietly(static fn (): bool => mkdir($directory, 0775, true)) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create the directory "%s".', $directory));
        }
        if (self::quietly(static fn (): int|false => file_put_contents($path, $contents)) === false) {
            throw new RuntimeException(sprintf('Unable to write "%s".', $path));
        }

        return $path;
    }

    /**
     * Runs a file operation without its PHP warning, so that error handlers that turn warnings
     * into exceptions (Laravel, PHPUnit) do not replace the RuntimeException of the caller.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private static function quietly(callable $operation): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
