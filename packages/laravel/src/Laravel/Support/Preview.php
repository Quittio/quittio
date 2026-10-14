<?php

declare(strict_types=1);

namespace Quittio\Laravel\Support;

use Illuminate\Contracts\View\Factory;
use InvalidArgumentException;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Testing\Scenarios;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Rendering\RenderedInvoice;
use Quittio\Laravel\Templates\BladeTemplateEngine;

/**
 * Renders the sample scenarios with a "PREVIEW" watermark.
 *
 * Template and locale may come from a request (preview route): only the views that rendering
 * accepts (see BladeTemplateEngine: "default", quittio.pdf.template, the shipped views under
 * "quittio::templates." and the views of quittio.pdf.templates, never partials) are rendered,
 * never file paths or other application views.
 */
final readonly class Preview
{
    public function __construct(
        private QuittioManager $quittio,
        private Factory $views,
    ) {}

    /**
     * @return list<string>
     */
    public function scenarios(): array
    {
        return Scenarios::names();
    }

    public function allowsTemplate(string $template): bool
    {
        $settings = $this->quittio->settings();
        if ($template === 'default' || $template === $settings->string('pdf.template', 'quittio::templates.default.layout')) {
            return true;
        }

        // View names only, never paths; the view must exist (otherwise the plain PHP templates
        // would be asked). Partials are no templates: rendered alone they fail on the
        // variables of the layout, so the engine refuses them.
        return preg_match('/^[A-Za-z0-9_.:-]+$/', $template) === 1
            && ! str_contains($template, '..')
            && BladeTemplateEngine::fromSettings($this->views, $settings)->allows($template)
            && $this->views->exists($template);
    }

    public function allowsLocale(string $locale): bool
    {
        return preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/', $locale) === 1;
    }

    public function render(string $scenario, string $format = 'pdf', ?string $template = null, ?string $locale = null): RenderedInvoice
    {
        if (! in_array($scenario, Scenarios::names(), true)) {
            throw new InvalidArgumentException(sprintf('Unknown scenario "%s". Known scenarios: %s.', $scenario, implode(', ', Scenarios::names())));
        }
        if (! in_array($format, ['pdf', 'html'], true)) {
            throw new InvalidArgumentException('The preview supports the formats pdf and html.');
        }
        if ($template !== null && ! $this->allowsTemplate($template)) {
            throw new InvalidArgumentException(sprintf('The preview renders only existing views that rendering accepts: "default", quittio.pdf.template, the views under "quittio::templates." and those of quittio.pdf.templates (no partials); "%s" is not one of them.', $template));
        }
        if ($locale !== null && ! $this->allowsLocale($locale)) {
            throw new InvalidArgumentException(sprintf('Invalid locale "%s".', $locale));
        }

        $invoice = Scenarios::make($scenario)->build();
        $locale ??= $invoice->meta->locale ?? 'en';
        // The watermark as option keeps the configured design (logo, colours, texts, paper).
        $pending = $this->quittio->make($invoice)
            ->format($format)
            ->locale($locale)
            ->validate(throwOnError: false)
            ->option('watermark', Translations::translator()->get('quittio::labels.preview', [], $locale));

        if ($template !== null) {
            $pending->template($template);
        }

        return $pending->render();
    }
}
