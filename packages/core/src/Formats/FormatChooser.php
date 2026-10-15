<?php

declare(strict_types=1);

namespace Quittio\Core\Formats;

use Quittio\Core\Config;
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Profiles\ProfileLevel;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Quittio;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Rendering\RunPresentation;

/**
 * Formats and profiles of a run. The FormatResolver decides which format an automatic
 * choice wants; this class applies the precedence of requested and configured profiles,
 * the EXTENDED upgrade and the checks of what a format allows.
 *
 * @internal
 */
final readonly class FormatChooser
{
    public function __construct(
        private Config $config,
        private ProfileRegistry $profiles,
        private FormatRegistry $formats,
        private FormatResolver $resolver,
        private RunPresentation $presentation,
    ) {}

    /**
     * Formats and profiles of a run: the requested formats, otherwise the configured format
     * or the automatic choice.
     *
     * Drafts and previews carry a watermark and must never look like an issued e-invoice:
     * no format of the run may produce e-invoice XML, also not next to a watermarked PDF.
     *
     * @return non-empty-list<array{OutputFormat, Profile}>
     */
    public function choose(InvoiceData $invoice, ResolvedCountries $countries, RenderOptions $options): array
    {
        $chosen = [];
        foreach ($options->formats === [] ? [$this->config->format] : $options->formats as $key) {
            $chosen[] = $this->chooseOne($invoice, $countries, $key, $options->profile);
        }

        if ($this->presentation->hasWatermark($invoice->presentation, $options)) {
            foreach ($chosen as [$format]) {
                if ($format->syntax !== null) {
                    throw new ConfigurationException(sprintf('The format "%s" produces e-invoice XML and cannot be rendered with a watermark (draft or preview). Render drafts with the format "pdf" or "html" only.', $format->key));
                }
            }
        }

        return $chosen;
    }

    /**
     * Syntax of the XML a format writes: its own syntax, or CII for "pdf" with b2cEmbedXml
     * (embedded as in ZUGFeRD) unless the run has a watermark; null = no XML.
     */
    public function xmlSyntax(OutputFormat $format, bool $watermarked): ?Syntax
    {
        return $format->syntax ?? ($format->pdf && $this->config->b2cEmbedXml && ! $watermarked ? Syntax::Cii : null);
    }

    /**
     * Format and profile for one requested format key (null = automatic choice).
     *
     * @return array{OutputFormat, Profile}
     */
    private function chooseOne(InvoiceData $invoice, ResolvedCountries $countries, ?string $key, Profile|string|null $requested): array
    {
        $resolvedProfile = null;
        if ($key === null) {
            $choice = $this->resolver->resolve($invoice, $countries);
            $key = $choice->format;
            $resolvedProfile = $choice->profile;
        }

        $format = $this->formats->get($key);
        // A requested profile wins; the configured one applies wherever the format allows it,
        // also when the format was requested or chosen by a country rule.
        $configured = $this->config->profile === null ? null : $this->profiles->get($this->config->profile);
        $profile = match (true) {
            $requested !== null => $this->profiles->get($requested),
            $configured !== null && $this->fits($format, $configured) => $configured,
            default => $this->profiles->get($resolvedProfile ?? $format->defaultProfile ?? Quittio::DEFAULT_PROFILE),
        };

        // Only formats that allow EXTENDED are upgraded; the others (e.g. XRechnung) report the
        // EXTENDED features in the validation instead.
        if ($requested === null && $format->syntax === Syntax::Cii && $format->allowsProfile('extended')
            && $profile->level() !== ProfileLevel::Extended && $profile->key() !== 'xrechnung-extension' && ExtendedFeatures::used($invoice)) {
            if (! $this->config->autoUpgradeProfile) {
                throw new ConfigurationException('The invoice uses features of profile EXTENDED (e.g. sub lines), but the automatic profile upgrade is switched off.');
            }
            $profile = $this->profiles->get('extended');
        }

        if (! $format->allowsProfile($profile->key())) {
            throw new ConfigurationException(sprintf('The format "%s" does not support profile "%s".', $format->key, $profile->key()));
        }
        if ($format->syntax !== null && ! in_array($format->syntax, $profile->syntaxes(), true)) {
            throw new ConfigurationException(sprintf('Profile "%s" is not available in syntax %s (format "%s").', $profile->key(), strtoupper($format->syntax->value), $format->key));
        }
        if ($format->embedsXml && $profile->embeddedFilename() === null) {
            throw new ConfigurationException(sprintf('Profile "%s" cannot be embedded into a PDF (format "%s").', $profile->key(), $format->key));
        }

        return [$format, $profile];
    }

    /**
     * Whether the profile can be used with the format (allowed profile, syntax, embedding).
     */
    private function fits(OutputFormat $format, Profile $profile): bool
    {
        return $format->allowsProfile($profile->key())
            && ($format->syntax === null || in_array($format->syntax, $profile->syntaxes(), true))
            && (! $format->embedsXml || $profile->embeddedFilename() !== null);
    }
}
