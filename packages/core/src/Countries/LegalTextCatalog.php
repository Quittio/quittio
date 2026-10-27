<?php

declare(strict_types=1);

namespace Quittio\Core\Countries;

/**
 * Legal and informational texts of a country pack per locale.
 */
final readonly class LegalTextCatalog
{
    /**
     * @param  array<string, array<string, string>>  $texts  key => [locale => text]
     */
    public function __construct(
        public array $texts = [],
        public string $fallbackLocale = 'en',
    ) {}

    /**
     * Whether the catalog has a text for $key in any locale.
     */
    public function has(string $key): bool
    {
        return isset($this->texts[$key]);
    }

    /**
     * @param  array<string, string>  $replace
     */
    public function get(string $key, ?string $locale = null, array $replace = []): ?string
    {
        $variants = $this->texts[$key] ?? null;
        if ($variants === null) {
            return null;
        }

        $text = $variants[$locale ?? $this->fallbackLocale] ?? $variants[$this->fallbackLocale] ?? array_values($variants)[0] ?? null;
        if ($text === null) {
            return null;
        }
        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, $value, $text);
        }

        return $text;
    }
}
