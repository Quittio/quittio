<?php

declare(strict_types=1);

namespace Quittio\Laravel\Templates;

use Illuminate\Contracts\View\Factory;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Templates\PhpTemplateEngine;
use Quittio\Core\Templates\TemplateEngine;
use Quittio\Core\View\InvoiceViewModel;
use Quittio\Laravel\Support\Settings;

/**
 * Renders Blade views with the invoice view model as $invoice. The name "default" maps to
 * the configured template. Template names may come from invoice data, so only these views
 * can be selected: the configured template (quittio.pdf.template), the shipped views under
 * "quittio::templates." (no partials) and the views of quittio.pdf.templates ("invoices.*"
 * or "namespace::*" allows every view below a prefix). Names that are no Blade view fall
 * back to the plain PHP templates, which resolve names inside their template directories
 * only (never file paths).
 */
final class BladeTemplateEngine implements TemplateEngine
{
    private const string SHIPPED = 'quittio::templates.';

    /**
     * @param  list<string>  $allowed  further selectable views or "prefix*" patterns
     */
    public function __construct(
        private readonly Factory $views,
        private readonly string $defaultView = 'quittio::templates.default.layout',
        private readonly PhpTemplateEngine $fallback = new PhpTemplateEngine,
        private readonly array $allowed = [],
    ) {}

    /**
     * The engine of the configuration: pdf.template for "default" and the views of
     * pdf.templates (a missing key means ['invoices.*'], the views of make:quittio-template).
     * Rendering and the preview use it, so both accept the same views.
     */
    public static function fromSettings(Factory $views, Settings $settings): self
    {
        return new self(
            $views,
            $settings->string('pdf.template', 'quittio::templates.default.layout'),
            allowed: $settings->get('pdf.templates') === null ? ['invoices.*'] : $settings->strings('pdf.templates'),
        );
    }

    public function render(string $template, InvoiceViewModel $invoice, array $data = []): string
    {
        $view = $template === 'default' ? $this->defaultView : $template;

        if (! $this->views->exists($view)) {
            return $this->fallback->render($template, $invoice, $data);
        }
        if (! $this->allows($view)) {
            throw new InvalidArgumentException(sprintf('The view "%s" is no invoice template: use "default", quittio.pdf.template, a view under "%s" or add it to quittio.pdf.templates.', $view, self::SHIPPED));
        }

        return $this->views->make($view, [...$data, 'invoice' => $invoice])->render();
    }

    /**
     * Whether $view may be selected: the configured template, a shipped view under
     * "quittio::templates." or a view of quittio.pdf.templates (an entry ending in "*" allows every
     * view with that prefix). Partials (".partials.") never are, except as the configured template.
     */
    public function allows(string $view): bool
    {
        if ($view === $this->defaultView) {
            return true;
        }
        if (str_contains($view, '.partials.')) {
            return false;
        }
        if (str_starts_with($view, self::SHIPPED)) {
            return true;
        }
        foreach ($this->allowed as $entry) {
            if ($entry === $view || (str_ends_with($entry, '*') && str_starts_with($view, substr($entry, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
}
