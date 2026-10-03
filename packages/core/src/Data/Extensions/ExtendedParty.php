<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Extensions;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\Contact;
use Quittio\Core\Mapping\Attributes\ListOf;

/**
 * Additional trade party of profile EXTENDED (invoicer, invoicee, ultimate customer, ship-from, line ship-to).
 */
final readonly class ExtendedParty
{
    use Immutable;

    /**
     * @param  list<Identifier>  $identifiers
     */
    public function __construct(
        public string $name,
        public ?Address $address = null,
        #[ListOf(Identifier::class)]
        public array $identifiers = [],
        public ?string $vatId = null,
        public ?Contact $contact = null,
    ) {}
}
