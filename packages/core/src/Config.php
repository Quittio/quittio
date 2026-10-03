<?php

declare(strict_types=1);

namespace Quittio\Core;

use Quittio\Core\Corrections\Cancellation;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\View\MandatoryFieldGuard;
use Quittio\Core\Xml\XmlSecurity;
use ReflectionClass;

/**
 * Configuration of the core without Laravel (specification 14.6). The Laravel package
 * builds it from config/quittio.php.
 */
final readonly class Config
{
    public const string VALIDATION_ERROR = 'error';

    public const string VALIDATION_WARN = 'warn';

    public const string VALIDATION_OFF = 'off';

    /**
     * @param  string|null  $format  default output format, null = automatic choice
     * @param  list<string>  $templatePaths  directories for plain PHP templates
     * @param  string  $validation  error (throw on errors), warn (attach result only) or off
     * @param  list<ValidationLevel>  $validationLevels  levels run while rendering
     * @param  array{mode?: string, url?: string, jar?: string|null, configurations?: list<array{scenarios: string, repository: string}>, java?: string, timeout?: int, fail_on_unavailable?: bool}|null  $kosit  null = disabled
     * @param  array{mode?: string, binary?: string, image?: string, docker?: string, timeout?: int, fail_on_unavailable?: bool}|null  $veraPdf  null = disabled
     * @param  array<string, mixed>  $options  options for validation rules and the view model
     * @param  Presentation|null  $presentation  design defaults (logo, colours, texts); the invoice's own values win
     * @param  int|null  $dueDays  due date (BT-9) = issue date + days for invoices without due date and payment terms
     * @param  string|null  $cancellationStrategy  credit_note or negative_invoice; null = default of the country pack
     */
    public function __construct(
        public ?string $format = null,
        public string $profile = 'en16931',
        public ?string $locale = null,
        public ?string $country = null,
        public string $template = 'default',
        public array $templatePaths = [],
        public string $filename = '{title}_{number}.{ext}',
        public bool $pdfa = true,
        public bool $b2cEmbedXml = false,
        public bool $autoUpgradeProfile = true,
        public string $validation = self::VALIDATION_ERROR,
        public array $validationLevels = [ValidationLevel::L1, ValidationLevel::L2, ValidationLevel::L3, ValidationLevel::L4, ValidationLevel::L7],
        public ?array $kosit = null,
        public ?array $veraPdf = null,
        public string $mandatoryFields = MandatoryFieldGuard::MODE_EXCEPTION,
        public string $girocodeAmount = 'due',
        public ?string $tempDir = null,
        public array $options = [],
        public ?Presentation $presentation = null,
        public bool $blockOnWarnings = false,
        public int $maxXmlBytes = XmlSecurity::DEFAULT_MAX_BYTES,
        public int $maxPdfBytes = 25 * 1024 * 1024,
        public int $maxDecompressedBytes = 50 * 1024 * 1024,
        public ?int $dueDays = null,
        public ?string $cancellationStrategy = null,
    ) {
        if ($cancellationStrategy !== null && ! in_array($cancellationStrategy, Cancellation::STRATEGIES, true)) {
            throw new ConfigurationException(sprintf('Unknown cancellation strategy "%s".', $cancellationStrategy));
        }
        if (! in_array($validation, [self::VALIDATION_ERROR, self::VALIDATION_WARN, self::VALIDATION_OFF], true)) {
            throw new ConfigurationException(sprintf('Unknown validation mode "%s".', $validation));
        }
    }

    /**
     * @param  array<string, mixed>  $config  keys as in config/quittio.php
     */
    public static function fromArray(array $config): self
    {
        $pdf = self::arr($config, 'pdf');
        $validation = self::arr($config, 'validation');

        $levels = [];
        foreach (self::arr($validation, 'levels') as $level) {
            $levels[] = $level instanceof ValidationLevel ? $level : ValidationLevel::from(is_string($level) ? strtoupper($level) : '');
        }

        $reader = self::arr($config, 'reader');
        $kosit = self::arr($validation, 'kosit');
        $veraPdf = self::arr($validation, 'verapdf');
        $defaults = new self;

        return new self(
            format: self::str($config, 'format') === 'auto' ? null : self::str($config, 'format'),
            profile: self::str($config, 'profile') ?? $defaults->profile,
            locale: self::str($config, 'locale'),
            country: self::str($config, 'country'),
            template: self::str($config, 'template') ?? $defaults->template,
            templatePaths: array_values(array_filter(self::arr($config, 'template_paths'), is_string(...))),
            filename: self::str($config, 'filename') ?? $defaults->filename,
            pdfa: self::bool($pdf, 'pdfa') ?? $defaults->pdfa,
            b2cEmbedXml: self::bool($pdf, 'b2c_embed_xml') ?? $defaults->b2cEmbedXml,
            autoUpgradeProfile: self::bool($config, 'auto_upgrade_profile') ?? $defaults->autoUpgradeProfile,
            validation: self::str($validation, 'mode') ?? $defaults->validation,
            validationLevels: $levels === [] ? $defaults->validationLevels : $levels,
            kosit: ($kosit['enabled'] ?? false) === true ? self::kositOptions($kosit) : null,
            veraPdf: ($veraPdf['enabled'] ?? false) === true ? self::veraPdfOptions($veraPdf) : null,
            mandatoryFields: self::str($config, 'mandatory_fields') ?? $defaults->mandatoryFields,
            girocodeAmount: self::str($pdf, 'girocode_amount') ?? $defaults->girocodeAmount,
            tempDir: self::str($config, 'temp_dir'),
            options: self::arr($config, 'options'),
            presentation: ($config['presentation'] ?? null) instanceof Presentation ? $config['presentation'] : null,
            blockOnWarnings: self::bool($validation, 'block_on_warnings') ?? $defaults->blockOnWarnings,
            maxXmlBytes: self::int($reader, 'max_xml_bytes') ?? $defaults->maxXmlBytes,
            maxPdfBytes: self::int($reader, 'max_pdf_bytes') ?? $defaults->maxPdfBytes,
            maxDecompressedBytes: self::int($reader, 'max_decompressed_bytes') ?? $defaults->maxDecompressedBytes,
            dueDays: self::int($config, 'due_days'),
            cancellationStrategy: self::str(self::arr($config, 'corrections'), 'cancellation_strategy'),
        );
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return (new ReflectionClass(self::class))->newInstanceArgs([...get_object_vars($this), ...$changes]);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{mode?: string, url?: string, jar?: string|null, configurations?: list<array{scenarios: string, repository: string}>, java?: string, timeout?: int, fail_on_unavailable?: bool}
     */
    private static function kositOptions(array $values): array
    {
        $options = [];
        foreach (['mode', 'url', 'jar', 'java'] as $key) {
            if (is_string($values[$key] ?? null)) {
                $options[$key] = $values[$key];
            }
        }
        if (is_int($values['timeout'] ?? null)) {
            $options['timeout'] = $values['timeout'];
        }
        if (is_bool($values['fail_on_unavailable'] ?? null)) {
            $options['fail_on_unavailable'] = $values['fail_on_unavailable'];
        }
        $configurations = [];
        foreach (is_array($values['configurations'] ?? null) ? $values['configurations'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['scenarios'] ?? null) && is_string($entry['repository'] ?? null)) {
                $configurations[] = ['scenarios' => $entry['scenarios'], 'repository' => $entry['repository']];
            }
        }
        if ($configurations !== []) {
            $options['configurations'] = $configurations;
        }

        return $options;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{mode?: string, binary?: string, image?: string, docker?: string, timeout?: int, fail_on_unavailable?: bool}
     */
    private static function veraPdfOptions(array $values): array
    {
        $options = [];
        foreach (['mode', 'binary', 'image', 'docker'] as $key) {
            if (is_string($values[$key] ?? null)) {
                $options[$key] = $values[$key];
            }
        }
        if (is_int($values['timeout'] ?? null)) {
            $options['timeout'] = $values['timeout'];
        }
        if (is_bool($values['fail_on_unavailable'] ?? null)) {
            $options['fail_on_unavailable'] = $values['fail_on_unavailable'];
        }

        return $options;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private static function arr(array $values, string $key): array
    {
        $value = $values[$key] ?? [];
        if (! is_array($value)) {
            throw new ConfigurationException(sprintf('The configuration value "%s" must be an array.', $key));
        }

        $result = [];
        foreach ($value as $k => $v) {
            $result[(string) $k] = $v;
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function str(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw new ConfigurationException(sprintf('The configuration value "%s" must be a string.', $key));
        }

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function int(array $values, string $key): ?int
    {
        $value = $values[$key] ?? null;
        if ($value !== null && ! is_int($value)) {
            throw new ConfigurationException(sprintf('The configuration value "%s" must be an integer.', $key));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function bool(array $values, string $key): ?bool
    {
        $value = $values[$key] ?? null;
        if ($value !== null && ! is_bool($value)) {
            throw new ConfigurationException(sprintf('The configuration value "%s" must be a boolean.', $key));
        }

        return $value;
    }
}
