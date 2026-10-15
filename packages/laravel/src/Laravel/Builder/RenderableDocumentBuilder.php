<?php

declare(strict_types=1);

namespace Quittio\Laravel\Builder;

use Illuminate\Container\Container;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Rendering\RenderedDocument;

/**
 * The document builder of Quittio::quote(), deliveryNote(), paymentReminder() …: renders
 * itself at the end of the chain.
 */
final class RenderableDocumentBuilder extends DocumentBuilder
{
    /**
     * Builds and renders the document as PDF. For further options (locale, template, HTML only)
     * use Quittio::document($builder).
     */
    public function render(): RenderedDocument
    {
        return Container::getInstance()->make(QuittioManager::class)->document($this)->render();
    }
}
