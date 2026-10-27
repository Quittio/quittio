<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Party;

use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Enums\ElectronicAddressScheme;

/**
 * Electronic address (BT-34 seller, BT-49 buyer) with its EAS scheme.
 * For Peppol, the electronic address is also the participant identifier.
 */
final readonly class ElectronicAddress
{
    use Immutable;

    public function __construct(
        public string $value,
        public ElectronicAddressScheme|RawCode $scheme,
    ) {}

    /**
     * An electronic address with the scheme as enum case; a scheme string without enum case becomes
     * a RawCode.
     */
    public static function of(string $value, ElectronicAddressScheme|RawCode|string $scheme): self
    {
        if (is_string($scheme)) {
            $scheme = ElectronicAddressScheme::tryFrom($scheme) ?? new RawCode($scheme);
        }

        return new self($value, $scheme);
    }
}
