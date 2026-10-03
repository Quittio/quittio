<?php

declare(strict_types=1);

namespace Quittio\Core\Contracts;

use Quittio\Core\Builder\PartyBuilder;

/**
 * Implemented by application models that describe a trade party (e.g. a customer).
 */
interface ProvidesParty
{
    public function fillParty(PartyBuilder $party): void;
}
