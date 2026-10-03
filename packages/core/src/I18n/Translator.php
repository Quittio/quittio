<?php

declare(strict_types=1);

namespace Quittio\Core\I18n;

/**
 * Minimal translator contract so that the core works without Laravel.
 * Keys use the form "group.key" (e.g. "labels.due_date", "rules.BR-CO-25.message").
 */
interface Translator
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public function get(string $key, array $replace = [], ?string $locale = null): string;

    public function has(string $key, ?string $locale = null): bool;

    public function locale(): string;

    /**
     * Registers a directory with {locale}/{group}.php files for a namespace (e.g. "quittio-de").
     */
    public function addPath(string $path, string $namespace = '*'): self;
}
