<?php

declare(strict_types=1);

namespace Quittio\Core\Templates;

use Quittio\Core\View\InvoiceViewModel;

/**
 * Turns the view model into HTML for the PDF renderer (Blade, plain PHP, Twig …).
 */
interface TemplateEngine
{
    /**
     * @param  array<string, mixed>  $data  additional variables
     */
    public function render(string $template, InvoiceViewModel $invoice, array $data = []): string;
}
