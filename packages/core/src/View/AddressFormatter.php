<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use Quittio\Core\Data\Party\Address;

/**
 * Formats postal addresses according to the conventions of a country.
 */
interface AddressFormatter
{
    /**
     * @param  list<string>  $nameLines  recipient lines placed before the address
     * @return list<string>
     */
    public function format(Address $address, array $nameLines = [], ?string $senderCountry = null, ?string $locale = null): array;
}
