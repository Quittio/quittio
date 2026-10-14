<?php

declare(strict_types=1);

namespace Quittio\Core\Templates;

use InvalidArgumentException;
use Quittio\Core\View\InvoiceViewModel;
use Stringable;
use Throwable;

/**
 * Plain PHP templates for use without Laravel.
 *
 * A template is a PHP file that receives $invoice (view model) and $e (escape function).
 * The name "default" refers to the template shipped with the core.
 *
 * Template names are never file paths: "custom" or "invoices/modern" resolve to
 * "{path}/{name}.php" inside one of the configured directories (or the shipped templates),
 * so a name from a request or from invoice data cannot include an arbitrary file.
 */
final class PhpTemplateEngine implements TemplateEngine
{
    /**
     * @param  list<string>  $paths  directories searched for "{name}.php"
     */
    public function __construct(
        private readonly array $paths = [],
    ) {}

    public function render(string $template, InvoiceViewModel $invoice, array $data = []): string
    {
        $file = $this->resolve($template);
        $e = static fn (mixed $value): string => htmlspecialchars((string) (is_scalar($value) || $value instanceof Stringable ? $value : ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, InvoiceViewModel $invoice, callable $e, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, $invoice, $e, $data);

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $exception;
        }
    }

    private function resolve(string $template): string
    {
        // Names only: no absolute paths, no stream wrappers, no parent directories.
        if (preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)*$#', $template) !== 1 || str_contains($template, '..')) {
            throw new InvalidArgumentException(sprintf('Invalid template name "%s": use a name inside a configured template directory, not a file path.', $template));
        }

        foreach ([...$this->paths, dirname(__DIR__, 2).'/resources/templates'] as $path) {
            $root = realpath($path);
            $file = $root === false ? false : realpath($root.'/'.$template.'.php');
            if ($root !== false && $file !== false && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file)) {
                return $file;
            }
        }

        throw new InvalidArgumentException(sprintf('Template "%s" not found.', $template));
    }
}
