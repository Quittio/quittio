<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Quittio\Core\Exceptions\LogicException;
use Quittio\Core\Quittio;
use Quittio\Core\Rendering\RenderedDocument;

/**
 * The document builder of Quittio::quote(), deliveryNote(), paymentReminder() …: bound to the
 * Quittio instance that started it, it renders itself at the end of the chain. The Laravel
 * counterpart is Quittio\Laravel\Builder\RenderableDocumentBuilder.
 */
final class BoundDocumentBuilder extends DocumentBuilder
{
    private ?Quittio $quittio = null;

    /**
     * A new builder for a document of $type bound to $quittio, without defaults.
     *
     * @param  int  $dunningLevel  for payment reminders: 0 = payment reminder, 1 = first reminder …
     *
     * @internal use Quittio::quote(), deliveryNote() …, which also apply the configured seller
     */
    public static function boundTo(Quittio $quittio, DocumentType $type, int $dunningLevel = 0): self
    {
        $builder = $type === DocumentType::PaymentReminder ? self::paymentReminder($dunningLevel) : self::make($type);
        $builder->quittio = $quittio;

        return $builder;
    }

    /**
     * Builds and renders the document as PDF. For further options (locale, template, HTML only)
     * use $quittio->document($builder).
     */
    public function render(): RenderedDocument
    {
        $quittio = $this->quittio ?? throw new LogicException('This builder was not started with a document method of Quittio (quote() …); render it with $quittio->document($builder).');

        return $quittio->document($this)->render();
    }
}
