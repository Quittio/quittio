<?php

declare(strict_types=1);

use Quittio\Core\Config;
use Quittio\Core\Countries\CountryRegistry;
use Quittio\Core\Countries\DefaultCountryResolver;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Formats\DefaultFormatResolver;
use Quittio\Core\Formats\FormatChooser;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Rendering\RunPresentation;
use Quittio\Core\Testing\Scenarios;

/**
 * @return list<array{string, string}> format and profile keys of the run
 */
function chosen(InvoiceData $invoice, RenderOptions $options = new RenderOptions, Config $config = new Config): array
{
    $chooser = new FormatChooser($config, ProfileRegistry::withDefaults(), FormatRegistry::withDefaults(), new DefaultFormatResolver($config->profile ?? 'en16931', $config->autoUpgradeProfile), new RunPresentation($config));
    $countries = (new DefaultCountryResolver(new CountryRegistry))->resolve($invoice);

    return array_map(fn (array $choice): array => [$choice[0]->key, $choice[1]->key()], $chooser->choose($invoice, $countries, $options));
}

it('chooses every requested format with its default profile', function () {
    expect(chosen(Scenarios::simple()->build(), new RenderOptions(formats: ['zugferd', 'ubl', 'pdf'])))
        ->toBe([['zugferd', 'en16931'], ['ubl', 'en16931'], ['pdf', 'en16931']]);
});

it('lets a requested profile win over the configured one', function () {
    $config = new Config(profile: 'basic');

    expect(chosen(Scenarios::simple()->build(), new RenderOptions(formats: ['zugferd'], profile: 'extended'), $config))->toBe([['zugferd', 'extended']])
        ->and(chosen(Scenarios::simple()->build(), new RenderOptions(formats: ['zugferd']), $config))->toBe([['zugferd', 'basic']]);
});

it('upgrades to EXTENDED for sub lines unless the upgrade is switched off', function () {
    $invoice = Scenarios::subLines()->build();

    expect(chosen($invoice, new RenderOptions(formats: ['zugferd'])))->toBe([['zugferd', 'extended']])
        ->and(fn () => chosen($invoice, new RenderOptions(formats: ['zugferd']), new Config(autoUpgradeProfile: false)))
        ->toThrow(ConfigurationException::class, 'automatic profile upgrade is switched off');
});

it('refuses profiles the format cannot write', function () {
    expect(fn () => chosen(Scenarios::simple()->build(), new RenderOptions(formats: ['ubl'], profile: 'basic')))
        ->toThrow(ConfigurationException::class);
});

it('refuses e-invoice XML for watermarked invoices', function () {
    $invoice = Scenarios::simple()->presentation(new Presentation(watermark: 'DRAFT'))->build();

    expect(chosen($invoice, new RenderOptions(formats: ['pdf'])))->toBe([['pdf', 'en16931']])
        ->and(fn () => chosen($invoice, new RenderOptions(formats: ['pdf', 'zugferd'])))
        ->toThrow(ConfigurationException::class, 'cannot be rendered with a watermark');
});

it('embeds CII into plain PDFs only with b2cEmbedXml and without a watermark', function () {
    $pdf = FormatRegistry::withDefaults()->get('pdf');
    $chooser = fn (bool $embed) => new FormatChooser(new Config(b2cEmbedXml: $embed), ProfileRegistry::withDefaults(), FormatRegistry::withDefaults(), new DefaultFormatResolver, new RunPresentation(new Config));

    expect($chooser(true)->xmlSyntax($pdf, false))->toBe(Syntax::Cii)
        ->and($chooser(true)->xmlSyntax($pdf, true))->toBeNull()
        ->and($chooser(false)->xmlSyntax($pdf, false))->toBeNull();
});
