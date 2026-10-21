<?php

declare(strict_types=1);

namespace Quittio\Core\Builder;

use Quittio\Core\Exceptions\LogicException;
use Quittio\Core\Quittio;
use Quittio\Core\Rendering\PendingInvoice;
use Quittio\Core\Rendering\RenderedInvoice;
use Quittio\Core\Validation\ValidationResult;

/**
 * The invoice builder of Quittio::invoice(): bound to the Quittio instance that started it, it
 * renders (or checks) itself at the end of the chain. The Laravel counterpart is
 * Quittio\Laravel\Builder\RenderableInvoiceBuilder.
 */
final class BoundInvoiceBuilder extends InvoiceBuilder
{
    private ?Quittio $quittio = null;

    /**
     * A new builder bound to $quittio, without defaults.
     *
     * @internal use Quittio::invoice(), which also applies the configured seller
     */
    public static function boundTo(Quittio $quittio): self
    {
        $builder = new self;
        $builder->quittio = $quittio;

        return $builder;
    }

    /**
     * Builds and renders the invoice: without $format in the configured or automatically
     * chosen format, otherwise in the given format, or formats (the first is the main result).
     * For further options use $quittio->make($builder).
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
        $quittio = $this->quittio ?? throw new LogicException('This builder was not started with Quittio::invoice(); render it with $quittio->make($builder).');
        $pending = $quittio->make($this);
        if (is_array($format)) {
            $pending->formats($format);
        } elseif ($format !== null) {
            $pending->format($format);
        }

        return $pending;
    }
}
