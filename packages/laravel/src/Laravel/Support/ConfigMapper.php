<?php

declare(strict_types=1);

namespace Quittio\Laravel\Support;

use Quittio\Core\Calculation\CalculationPolicy;
use Quittio\Core\Config;
use Quittio\Core\Data\Presentation\Colors;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Validation\RuleOverrides;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\View\MandatoryFieldGuard;
use Quittio\Core\View\ViewModelFactory;

/**
 * Translates config/quittio.php into the core configuration.
 *
 * @internal
 */
final class ConfigMapper
{
    /**
     * @param  list<string>|null  $levels  validation levels instead of validation.levels (e.g. quittio:validate --kosit)
     * @param  string|null  $defaultCountry  country without defaults.country, e.g. the only installed pack
     */
    public static function toCore(Settings $settings, bool $production, ?array $levels = null, ?string $defaultCountry = null): Config
    {
        // A published file without the key keeps the default checks; only [] switches them off.
        $levelNames = $levels ?? ($settings->get('validation.levels') === null ? ['php', 'xsd'] : $settings->strings('validation.levels'));
        $levels = [];
        if (in_array('php', $levelNames, true)) {
            array_push($levels, ValidationLevel::L1, ValidationLevel::L2, ValidationLevel::L3, ValidationLevel::L7);
        }
        if (in_array('xsd', $levelNames, true)) {
            $levels[] = ValidationLevel::L4;
        }

        $kosit = $settings->section('validation.drivers.kosit');
        $veraPdf = $settings->section('validation.drivers.verapdf');
        // "auto" = automatic choice, as in Config::fromArray().
        $format = $settings->nullableString('defaults.format');

        return new Config(
            format: $format === 'auto' ? null : $format,
            profile: $settings->nullableString('defaults.profile'),
            locale: $settings->nullableString('defaults.locale'),
            country: $settings->nullableString('defaults.country') ?? $defaultCountry,
            template: 'default',
            filename: $settings->string('pdf.filename', '{title}_{number}.{ext}'),
            pdfa: $settings->bool('pdf.pdfa', true),
            b2cEmbedXml: $settings->bool('format_resolver.b2c_embed_xml', false),
            autoUpgradeProfile: $settings->bool('format_resolver.auto_upgrade_profile', true),
            validation: $settings->string('validation.mode', Config::VALIDATION_ERROR),
            validationLevels: $levels,
            kosit: in_array('kosit', $levelNames, true) ? self::kosit($kosit) : null,
            veraPdf: in_array('verapdf', $levelNames, true) ? [
                'mode' => $veraPdf->string('mode', 'cli'),
                'binary' => $veraPdf->string('binary', 'verapdf'),
                'image' => $veraPdf->string('image', 'verapdf/cli'),
                'docker' => $veraPdf->string('docker', 'docker'),
                'timeout' => $veraPdf->int('timeout', 120),
                'fail_on_unavailable' => $veraPdf->bool('fail_on_unavailable', true),
            ] : null,
            mandatoryFields: $settings->nullableString('validation.mandatory_field_guard')
                ?? ($production ? MandatoryFieldGuard::MODE_LOG : MandatoryFieldGuard::MODE_EXCEPTION),
            girocodeAmount: $settings->string('pdf.payment_qr.girocode_amount', 'due'),
            tempDir: $settings->nullableString('pdf.cache_path'),
            options: [
                'fold_marks' => $settings->bool('pdf.fold_marks', true),
                // Descriptions and notes as Markdown instead of plain text (they are also in the XML).
                'markdown' => $settings->bool('pdf.markdown', false),
                // An explicit paper size wins over the country pack default, also "A4".
                'paper' => $settings->nullableString('pdf.paper'),
            ],
            presentation: self::presentation($settings),
            blockOnWarnings: $settings->bool('validation.block_on_warnings', false),
            // Laravel default: the messages follow the language of the application.
            validationLocale: $settings->nullableString('validation.locale') ?? 'app',
            rules: RuleOverrides::fromArray($settings->array('validation.rules')),
            maxXmlBytes: $settings->int('reader.max_xml_bytes', 10 * 1024 * 1024),
            maxPdfBytes: $settings->int('reader.max_pdf_bytes', 25 * 1024 * 1024),
            maxDecompressedBytes: $settings->int('reader.max_decompressed_bytes', 50 * 1024 * 1024),
            dueDays: self::dueDays($settings->get('defaults.due_days')),
            cancellationStrategy: $settings->nullableString('corrections.cancellation_strategy'),
            calculation: CalculationPolicy::fromArray($settings->array('calculation')),
        );
    }

    /**
     * Design level 1: logo, colours, font, texts and footer from the configuration.
     */
    public static function presentation(Settings $settings): Presentation
    {
        $colors = $settings->section('pdf.colors');
        $footer = [];
        foreach ($settings->array('pdf.footer_columns') as $column) {
            if (is_array($column)) {
                $footer[] = array_values(array_filter($column, is_string(...)));
            }
        }

        $paymentQr = $settings->string('pdf.payment_qr.type', Presentation::PAYMENT_QR_AUTO);
        if (! in_array($paymentQr, ViewModelFactory::PAYMENT_QR_MODES, true)) {
            throw new ConfigurationException(sprintf('quittio.pdf.payment_qr.type: unknown type "%s". Known types: %s.', $paymentQr, implode(', ', ViewModelFactory::PAYMENT_QR_MODES)));
        }

        return new Presentation(
            logo: $settings->nullableString('pdf.logo'),
            colors: new Colors(
                primary: $colors->string('primary', '#2741d9'),
                text: $colors->string('text', '#15171c'),
                muted: $colors->string('muted', '#5f6470'),
                secondary: $colors->nullableString('secondary'),
            ),
            font: $settings->nullableString('pdf.font'),
            senderLine: $settings->nullableString('pdf.sender_line'),
            letterLayout: $settings->nullableString('pdf.layout'),
            salutation: $settings->nullableString('pdf.texts.salutation'),
            introText: $settings->nullableString('pdf.texts.intro'),
            closingText: $settings->nullableString('pdf.texts.closing'),
            footerColumns: $footer,
            showItemNumbers: $settings->bool('pdf.show_item_numbers', false),
            paymentQr: $paymentQr,
            paper: $settings->nullableString('pdf.paper') ?? 'A4',
        );
    }

    /**
     * Days until the due date: an integer or a numeric string (e.g. from env()); null = none.
     */
    private static function dueDays(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1) {
            return (int) trim($value);
        }

        throw new ConfigurationException(sprintf('quittio.defaults.due_days must be a number of days, "%s" given.', is_string($value) ? $value : get_debug_type($value)));
    }

    /**
     * The main configuration (scenarios, repository) plus further scenario sets.
     *
     * @return list<array{scenarios: string, repository: string}>
     */
    public static function kositConfigurations(Settings $kosit): array
    {
        $configurations = [];
        $scenarios = $kosit->nullableString('scenarios');
        $repository = $kosit->nullableString('repository');
        if ($scenarios !== null && $repository !== null) {
            $configurations[] = ['scenarios' => $scenarios, 'repository' => $repository];
        }
        foreach ($kosit->array('configurations') as $entry) {
            if (is_array($entry) && is_string($entry['scenarios'] ?? null) && is_string($entry['repository'] ?? null)) {
                $configurations[] = ['scenarios' => $entry['scenarios'], 'repository' => $entry['repository']];
            }
        }

        return $configurations;
    }

    /**
     * @return array{mode: string, url: string, jar: string|null, configurations: list<array{scenarios: string, repository: string}>, java: string, timeout: int, fail_on_unavailable: bool}
     */
    private static function kosit(Settings $kosit): array
    {
        $configurations = self::kositConfigurations($kosit);

        return [
            'mode' => $kosit->string('mode', 'daemon'),
            'url' => $kosit->string('url', 'http://127.0.0.1:8081'),
            'jar' => $kosit->nullableString('jar'),
            'configurations' => $configurations,
            'java' => $kosit->string('java', 'java'),
            'timeout' => $kosit->int('timeout', 30),
            'fail_on_unavailable' => $kosit->bool('fail_on_unavailable', true),
        ];
    }
}
