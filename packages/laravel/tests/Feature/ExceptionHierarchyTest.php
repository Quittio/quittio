<?php

declare(strict_types=1);

use Brick\Math\Exception\NumberFormatException;
use Quittio\Core\Exceptions\QuittioException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Exceptions that are not errors of the library: an unknown macro, the notation check of
 * brick/math and the HTTP responses of the preview route (HttpExceptionInterface).
 */
const FOREIGN_EXCEPTIONS = [BadMethodCallException::class, NumberFormatException::class];

it('throws only exceptions that implement QuittioException', function () {
    $root = dirname(__DIR__, 3);
    $foreign = [];
    foreach (['core/src', 'laravel/src', 'country-de/src'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            preg_match('/^namespace ([^;]+);/m', $code, $namespace);
            preg_match_all('/^use ([^;\s]+)(?: as (\w+))?;/m', $code, $imports, PREG_SET_ORDER);
            $aliases = [];
            foreach ($imports as $import) {
                $aliases[$import[2] ?? substr((string) strrchr('\\'.$import[1], '\\'), 1)] = $import[1];
            }
            preg_match_all('/throw new (\\\\?[\w\\\\]+)/', $code, $throws);
            foreach ($throws[1] as $name) {
                $class = str_starts_with($name, '\\') ? substr($name, 1) : ($aliases[$name] ?? ($namespace[1] ?? '').'\\'.$name);
                if (! is_a($class, QuittioException::class, true) && ! is_a($class, HttpExceptionInterface::class, true) && ! in_array($class, FOREIGN_EXCEPTIONS, true)) {
                    $foreign[] = substr($file->getPathname(), strlen($root) + 1).': '.$class;
                }
            }
        }
    }

    expect($foreign)->toBe([]);
});
