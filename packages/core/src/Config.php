<?php

declare(strict_types=1);

namespace Quittio\Core;

use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Calculation\CalculationPolicy;
use Quittio\Core\Corrections\Cancellation;
use Quittio\Core\Data\Party\SellerParty;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Validation\RuleOverrides;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\View\MandatoryFieldGuard;
use Quittio\Core\Xml\XmlSecurity;
use ReflectionClass;

/**
 * Configuration of the core without Laravel. The Laravel package
 * translates config/quittio.php into it (Quittio\Laravel\Support\ConfigMapper), whose layout
 * differs from the keys of fromArray().
 */
final readonly class Config
{
    public const string VALIDATION_ERROR = 'error';

    public const string VALIDATION_WARN = 'warn';

    public const string VALIDATION_OFF = 'off';

    /** The seller of Quittio::invoice() and the document methods (quote() …); null = none. */
    public ?SellerParty $seller;

    /**
     * @param  string|null  $format  default output format, null = automatic choice
     * @param  list<string>  $templatePaths  directories for plain PHP templates
     * @param  string|null  $profile  default profile for every format that allows it; null = the profile of the format or of the automatic choice (EN 16931 for ZUGFeRD/Factur-X)
     * @param  string  $validation  error (throw on errors), warn (attach result only) or off
     * @param  list<ValidationLevel>  $validationLevels  levels run while rendering
     * @param  array{mode?: string, url?: string, jar?: string|null, configurations?: list<array{scenarios: string, repository: string}>, java?: string, timeout?: int, fail_on_unavailable?: bool}|null  $kosit  null = disabled
     * @param  array{mode?: string, binary?: string, image?: string, docker?: string, timeout?: int, fail_on_unavailable?: bool}|null  $veraPdf  null = disabled
     * @param  array<string, mixed>  $options  options for validation rules and the view model
     * @param  Presentation|null  $presentation  design defaults (logo, colours, texts); the invoice's own values win
     * @param  int|null  $dueDays  due date (BT-9) = issue date + days for invoices without due date and payment terms
     * @param  string|null  $cancellationStrategy  credit_note or negative_invoice; null = default of the country pack
     * @param  string|null  $validationLocale  language of validation messages: null or "invoice" = the language of the
     *         invoice, "app" = the language of the application translator (Laravel: app()->getLocale()), or a locale
     * @param  RuleOverrides  $rules  PHP rules switched off, re-rated or replaced by code
     * @param  CalculationPolicy  $calculation  what the calculator does with declared amounts
     * @param  array<string, array<string, mixed>>  $countrySettings  settings of the country packs by lower-case
     *         code, e.g. ['de' => ['consumer_default_notice' => false]] (Laravel: quittio.countries.de)
     * @param  SellerParty|PartyBuilder|null  $seller  the seller that Quittio::invoice() and the document methods
     *         start with; a PartyBuilder (Party::make()) is built here once
     * @param  array{iban: string, bic?: string|null, name?: string|null}|null  $bankAccount  the seller's account
     *         that Quittio::invoice() and the document methods add as credit transfer (BG-17); needs $seller
     * @param  bool  $smallBusiness  the seller is a small business: Quittio::invoice() and the document methods
     *         set Meta::FLAG_SMALL_BUSINESS, so country packs reject VAT on them; needs $seller
     *
     * @throws ConfigurationException for invalid values, a seller that cannot be built (name, postal address)
     *         or a bank account or small business flag without seller
     */
    public function __construct(
        public ?string $format = null,
        public ?string $profile = null,
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
        public ?string $validationLocale = null,
        public RuleOverrides $rules = new RuleOverrides,
        public CalculationPolicy $calculation = new CalculationPolicy,
        public array $countrySettings = [],
        SellerParty|PartyBuilder|null $seller = null,
        public ?array $bankAccount = null,
        public bool $smallBusiness = false,
    ) {
        if ($cancellationStrategy !== null && ! in_array($cancellationStrategy, Cancellation::STRATEGIES, true)) {
            throw new ConfigurationException(sprintf('Unknown cancellation strategy "%s".', $cancellationStrategy));
        }
        if (! in_array($validation, [self::VALIDATION_ERROR, self::VALIDATION_WARN, self::VALIDATION_OFF], true)) {
            throw new ConfigurationException(sprintf('Unknown validation mode "%s".', $validation));
        }
        try {
            $this->seller = $seller instanceof PartyBuilder ? $seller->buildSeller() : $seller;
        } catch (InvalidInvoiceData $e) {
            throw new ConfigurationException(sprintf('The configured seller cannot be used: %s', $e->getMessage()), previous: $e);
        }
        if ($bankAccount !== null && trim($bankAccount['iban']) === '') {
            throw new ConfigurationException('The configured bank account needs an IBAN.');
        }
        if ($this->seller === null && ($bankAccount !== null || $smallBusiness)) {
            throw new ConfigurationException('A bank account or the small business flag is configured without a seller: they are defaults of the seller.');
        }
    }

    /**
     * Builds the configuration from an array with the core's own, flat layout (not the layout
     * of the Laravel config/quittio.php). Keys, all optional:
     *
     * - format ("auto" or null = automatic choice), profile, locale, country, due_days
     * - seller (a PartyBuilder from Party::make() or a SellerParty), bank_account (['iban' => …, 'bic' => …,
     *   'name' => …], bic and name optional), small_business (bool): the seller and its defaults that
     *   Quittio::invoice() and the document methods (quote() …) start with
     * - template, template_paths (directories with {name}.php), filename
     * - pdf.pdfa, pdf.b2c_embed_xml, pdf.girocode_amount (due, discounted)
     * - auto_upgrade_profile
     * - validation.mode (error, warn, off), validation.levels (enum names L1 … L7, e.g. ['L1', 'L4']),
     *   validation.block_on_warnings, validation.rules (disabled, severity, allow_official; see RuleOverrides)
     * - validation.kosit, validation.verapdf: ['enabled' => true, …] switches the driver on; the other keys
     *   are those of the $kosit and $veraPdf parameters (KoSIT scenario sets only in "configurations")
     * - mandatory_fields (exception, log, off), temp_dir, options (e.g. paper, markdown, fold_marks, watermark)
     * - presentation (a Presentation object)
     * - reader.max_xml_bytes, reader.max_pdf_bytes, reader.max_decompressed_bytes
     * - corrections.cancellation_strategy
     * - countries.{code}: settings of a country pack, e.g. countries.de.consumer_default_notice
     * - calculation.declared_amounts (keep, recalculate, reject; see DeclaredAmountsMode)
     *
     * The Laravel names differ, e.g. defaults.format, format_resolver.b2c_embed_xml,
     * validation.levels php/xsd/kosit/verapdf, validation.mandatory_field_guard and pdf.cache_path.
     *
     * @param  array<string, mixed>  $config
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
            validationLocale: self::str($validation, 'locale'),
            rules: RuleOverrides::fromArray(self::arr($validation, 'rules')),
            calculation: CalculationPolicy::fromArray(self::arr($config, 'calculation')),
            countrySettings: self::countrySettings(self::arr($config, 'countries')),
            seller: self::seller($config),
            bankAccount: self::bankAccount($config),
            smallBusiness: self::bool($config, 'small_business') ?? $defaults->smallBusiness,
        );
    }

    /**
     * The settings of the country packs: the entries with a lower-case code and an array, e.g.
     * 'de' => ['consumer_default_notice' => false]; others (e.g. pack classes) are left out.
     *
     * @param  array<array-key, mixed>  $countries
     * @return array<string, array<string, mixed>>
     */
    public static function countrySettings(array $countries): array
    {
        $settings = [];
        foreach ($countries as $code => $values) {
            if (is_string($code) && preg_match('/^[a-z]{2}$/', $code) === 1 && is_array($values)) {
                $settings[$code] = array_filter($values, is_string(...), ARRAY_FILTER_USE_KEY);
            }
        }

        return $settings;
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
     * @param  array<array-key, mixed>  $config
     */
    private static function seller(array $config): SellerParty|PartyBuilder|null
    {
        $value = $config['seller'] ?? null;
        if ($value !== null && ! $value instanceof SellerParty && ! $value instanceof PartyBuilder) {
            throw new ConfigurationException(sprintf('The configuration value "seller" must be a PartyBuilder (Party::make()) or a SellerParty, %s given.', get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return array{iban: string, bic: string|null, name: string|null}|null
     */
    private static function bankAccount(array $config): ?array
    {
        if (($config['bank_account'] ?? null) === null) {
            return null;
        }
        $account = self::arr($config, 'bank_account');
        $iban = $account['iban'] ?? null;
        if (! is_string($iban) || trim($iban) === '') {
            throw new ConfigurationException('The configuration value "bank_account.iban" must be a non-empty string.');
        }

        return ['iban' => $iban, 'bic' => self::str($account, 'bic'), 'name' => self::str($account, 'name')];
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
