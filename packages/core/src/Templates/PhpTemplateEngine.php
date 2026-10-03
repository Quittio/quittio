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
        if (is_file($template)) {
            return $template;
        }

        $paths = [...$this->paths, dirname(__DIR__, 2).'/resources/templates'];
        foreach ($paths as $path) {
            $file = rtrim($path, '/').'/'.$template.'.php';
            if (is_file($file)) {
                return $file;
            }
        }

        throw new InvalidArgumentException(sprintf('Template "%s" not found.', $template));
    }
}
