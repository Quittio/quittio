<?php

declare(strict_types=1);

namespace Quittio\Laravel\Support;

use Quittio\Core\Config;
use Quittio\Core\Data\Presentation\Colors;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\View\MandatoryFieldGuard;

/**
 * Translates config/quittio.php into the core configuration.
 */
final class ConfigMapper
{
    public static function toCore(Settings $settings, bool $production): Config
    {
        $levelNames = $settings->strings('validation.levels');
        $levels = [];
        if (in_array('php', $levelNames, true)) {
            array_push($levels, ValidationLevel::L1, ValidationLevel::L2, ValidationLevel::L3, ValidationLevel::L7);
        }
        if (in_array('xsd', $levelNames, true)) {
            $levels[] = ValidationLevel::L4;
        }

        $kosit = $settings->section('validation.drivers.kosit');
        $veraPdf = $settings->section('validation.drivers.verapdf');

        return new Config(
            format: $settings->nullableString('defaults.format'),
            profile: $settings->nullableString('defaults.profile') ?? 'en16931',
            locale: $settings->nullableString('defaults.locale'),
            country: $settings->nullableString('defaults.country'),
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
                'timeout' => $veraPdf->int('timeout', 120),
                'fail_on_unavailable' => $veraPdf->bool('fail_on_unavailable', true),
            ] : null,
            mandatoryFields: $settings->nullableString('validation.mandatory_field_guard')
                ?? ($production ? MandatoryFieldGuard::MODE_LOG : MandatoryFieldGuard::MODE_EXCEPTION),
            girocodeAmount: $settings->string('pdf.payment_qr.girocode_amount', 'due'),
            tempDir: $settings->nullableString('pdf.cache_path'),
            options: ['fold_marks' => $settings->bool('pdf.fold_marks', false)],
            presentation: self::presentation($settings),
            blockOnWarnings: $settings->bool('validation.block_on_warnings', false),
            maxXmlBytes: $settings->int('reader.max_xml_bytes', 10 * 1024 * 1024),
            maxPdfBytes: $settings->int('reader.max_pdf_bytes', 25 * 1024 * 1024),
            maxDecompressedBytes: $settings->int('reader.max_decompressed_bytes', 50 * 1024 * 1024),
            dueDays: is_int($settings->get('defaults.due_days')) ? $settings->int('defaults.due_days', 14) : null,
            cancellationStrategy: $settings->nullableString('corrections.cancellation_strategy'),
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

        return new Presentation(
            logo: $settings->nullableString('pdf.logo'),
            colors: new Colors(
                primary: $colors->string('primary', '#1e40af'),
                text: $colors->string('text', '#111827'),
                muted: $colors->string('muted', '#6b7280'),
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
            paymentQr: $settings->string('pdf.payment_qr.type', Presentation::PAYMENT_QR_AUTO),
            paper: $settings->string('pdf.paper', 'A4'),
        );
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
