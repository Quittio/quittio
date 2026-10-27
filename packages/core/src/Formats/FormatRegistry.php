<?php

declare(strict_types=1);

namespace Quittio\Core\Formats;

use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Profiles\Syntax;

/**
 * Known output formats. Country and network packages can register national formats.
 */
final class FormatRegistry
{
    public const string ZUGFERD = 'zugferd';

    public const string FACTUR_X = 'factur-x';

    public const string CII = 'cii';

    public const string UBL = 'ubl';

    public const string PEPPOL = 'peppol';

    public const string PDF = 'pdf';

    public const string HTML = 'html';

    public const string JSON = 'json';

    /** @var array<string, OutputFormat> */
    private array $formats = [];

    /** @var array<string, string> */
    private array $aliases = [];

    public static function withDefaults(): self
    {
        $registry = new self;
        $registry->register(new OutputFormat(self::ZUGFERD, Syntax::Cii, pdf: true, embedsXml: true, defaultProfile: 'en16931'));
        $registry->register(new OutputFormat(self::CII, Syntax::Cii, defaultProfile: 'en16931'));
        $registry->register(new OutputFormat(self::UBL, Syntax::Ubl, defaultProfile: 'en16931'));
        $registry->register(new OutputFormat(self::PEPPOL, Syntax::Ubl, defaultProfile: 'peppol', profiles: ['peppol']));
        $registry->register(new OutputFormat(self::PDF, pdf: true));
        $registry->register(new OutputFormat(self::HTML, html: true));
        $registry->register(new OutputFormat(self::JSON, json: true));
        $registry->alias(self::FACTUR_X, self::ZUGFERD);
        $registry->alias('facturx', self::ZUGFERD);

        return $registry;
    }

    public function register(OutputFormat $format): self
    {
        $this->formats[$format->key] = $format;

        return $this;
    }

    public function alias(string $alias, string $key): self
    {
        $this->aliases[strtolower($alias)] = $key;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->formats[$this->resolveKey($key)]);
    }

    public function get(string $key): OutputFormat
    {
        return $this->formats[$this->resolveKey($key)]
            ?? throw new ConfigurationException(sprintf('Unknown format "%s". Known formats: %s.', $key, implode(', ', array_keys($this->formats))));
    }

    /**
     * @return array<string, OutputFormat>
     */
    public function all(): array
    {
        return $this->formats;
    }

    private function resolveKey(string $key): string
    {
        $key = strtolower($key);

        return $this->aliases[$key] ?? $key;
    }
}
