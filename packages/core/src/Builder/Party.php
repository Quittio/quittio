<?php

declare(strict_types=1);

namespace Quittio\Core\Builder;

/**
 * Starts a seller or buyer for InvoiceBuilder::seller() and buyer() without a callback:
 * ->buyer(Party::make('Kunde AG')->vatId('DE987654321')->address('Weg 1', '54321', 'Beispielstadt', 'DE')).
 */
final class Party
{
    /**
     * A new PartyBuilder with the name (BT-27 for the seller, BT-44 for the buyer) already set.
     */
    public static function make(string $name): PartyBuilder
    {
        return (new PartyBuilder)->name($name);
    }
}
