<?php

declare(strict_types=1);

namespace Quittio\Laravel\Builder;

use Illuminate\Container\Container;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Rendering\PendingInvoice;
use Quittio\Laravel\Rendering\RenderedInvoice;

/**
 * The invoice builder of Quittio::invoice(): renders (or checks) itself at the end of the chain.
 */
final class RenderableInvoiceBuilder extends InvoiceBuilder
{
    /**
     * Builds and renders the invoice: without $format in the configured or automatically
     * chosen format, otherwise in the given format, or formats (the first is the main result).
     * For further options use Quittio::make($builder).
     *
     * @param  string|list<string>|null  $format
     */
    public function render(string|array|null $format = null): RenderedInvoice
    {
        return $this->pending($format)->render();
    }

    /**
     * Validates the invoice like render() would, including the XML, without rendering a PDF;
     * returns the result and does not throw for invalid data.
     *
     * @param  string|list<string>|null  $format
     */
    public function check(string|array|null $format = null): ValidationResult
    {
        return $this->pending($format)->check();
    }

    /**
     * @param  string|list<string>|null  $format
     */
    private function pending(string|array|null $format): PendingInvoice
    {
        $pending = Container::getInstance()->make(QuittioManager::class)->make($this);
        if (is_array($format)) {
            $pending->formats($format);
        } elseif ($format !== null) {
            $pending->format($format);
        }

        return $pending;
    }
}
