<?php

declare(strict_types=1);

namespace Quittio\Laravel\Templates;

use Illuminate\Contracts\View\Factory;
use Quittio\Core\Templates\PhpTemplateEngine;
use Quittio\Core\Templates\TemplateEngine;
use Quittio\Core\View\InvoiceViewModel;

/**
 * Renders Blade views with the invoice view model as $invoice. The name "default"
 * maps to the configured template; plain PHP files remain usable as fallback.
 */
final class BladeTemplateEngine implements TemplateEngine
{
    public function __construct(
        private readonly Factory $views,
        private readonly string $defaultView = 'quittio::templates.default.layout',
        private readonly PhpTemplateEngine $fallback = new PhpTemplateEngine,
    ) {}

    public function render(string $template, InvoiceViewModel $invoice, array $data = []): string
    {
        $view = $template === 'default' ? $this->defaultView : $template;

        if (! $this->views->exists($view)) {
            return $this->fallback->render($template, $invoice, $data);
        }

        return $this->views->make($view, [...$data, 'invoice' => $invoice])->render();
    }
}
