<?php

declare(strict_types=1);

namespace Quittio\Laravel\Support;

use Illuminate\Translation\Translator as IlluminateTranslator;
use Quittio\Core\I18n\Translator;

/**
 * Bridges the core translator contract to Laravel's translator, so that applications
 * can override labels and messages in lang/vendor/quittio.
 *
 * @internal
 */
final class LaravelTranslator implements Translator
{
    public function __construct(
        private readonly IlluminateTranslator $translator,
    ) {}

    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $value = $this->translator->get($key, $replace, $locale);

        return is_string($value) ? $value : $key;
    }

    public function has(string $key, ?string $locale = null): bool
    {
        return $this->translator->has($key, $locale);
    }

    public function locale(): string
    {
        return $this->translator->getLocale();
    }

    public function addPath(string $path, string $namespace = '*'): self
    {
        if ($namespace === '*') {
            $this->translator->addJsonPath($path);
        } else {
            $this->translator->addNamespace($namespace, $path);
        }

        return $this;
    }
}
