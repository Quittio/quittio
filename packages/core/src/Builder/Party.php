<?php

declare(strict_types=1);

namespace Quittio\Core\Builder;

/**
 * Starts a seller or buyer for InvoiceBuilder::seller() and buyer() without a callback:
 * ->buyer(Party::make('Kunde AG')->vatId('DE987654321')->address('Weg 1', '54321', 'Beispielstadt', 'DE')).
 */
final class Party
{
    public static function make(string $name): PartyBuilder
    {
        return (new PartyBuilder)->name($name);
    }
}
