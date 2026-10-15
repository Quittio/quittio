<?php

declare(strict_types=1);

namespace Quittio\Core\Rendering;

use Quittio\Core\Config;
use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\View\ViewModelFactory;

/**
 * The presentation decisions of a run that format choice and rendering share: presentation
 * defaults, watermark, template and paper size.
 *
 * @internal
 */
final readonly class RunPresentation
{
    public function __construct(
        private Config $config,
    ) {}

    /**
     * Presentation defaults of a run: the presentation of the call over the configured one,
     * property by property (unset values of the call keep the configured design).
     */
    public function defaults(RenderOptions $options): ?Presentation
    {
        $call = $options->presentation;
        $configured = $this->config->presentation;
        if ($call === null || $configured === null) {
            return $call ?? $configured;
        }

        return ViewModelFactory::mergePresentation($call, $configured);
    }

    /**
     * Same precedence as the view model: invoice presentation, then the presentation
     * defaults of the call and the configuration, then the "watermark" option.
     */
    public function hasWatermark(?Presentation $own, RenderOptions $options): bool
    {
        $option = $options->options['watermark'] ?? $this->config->options['watermark'] ?? null;
        $defaults = $this->defaults($options);
        $watermark = $own === null ? $defaults?->watermark : ViewModelFactory::mergePresentation($own, $defaults ?? new Presentation)->watermark;

        return ($watermark ?? (is_string($option) ? $option : null)) !== null;
    }

    /**
     * Template of a run: the call (template()), the invoice presentation, the presentation
     * defaults of the call and the configuration, then the configured template.
     */
    public function template(?Presentation $own, RenderOptions $options): string
    {
        return $options->template ?? $own->template ?? $this->defaults($options)->template ?? $this->config->template;
    }

    /**
     * Paper size: invoice presentation, then the presentation defaults of the call and the
     * configuration, then an explicitly configured size (option "paper", e.g. pdf.paper),
     * then the country pack. The presentation default "A4" counts as not set unless the
     * presentation names "paper" in $explicit.
     */
    public function paper(?Presentation $own, RenderOptions $options, CountryPack $pack): string
    {
        $default = (new Presentation)->paper;
        foreach ([$own, $this->defaults($options)] as $presentation) {
            if ($presentation !== null && $presentation->paper !== '' && ($presentation->paper !== $default || $presentation->isExplicit('paper'))) {
                return $presentation->paper;
            }
        }

        $explicit = $options->options['paper'] ?? $this->config->options['paper'] ?? null;

        return is_string($explicit) && $explicit !== '' ? $explicit : $pack->defaults()->paper;
    }
}
