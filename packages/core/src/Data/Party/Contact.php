<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Party;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;

/**
 * Contact (BG-6 seller: BT-41/42/43, BG-9 buyer: BT-56/57/58).
 */
final readonly class Contact
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-41'), Q\Cardinality('0..1'), Q\Cii('ram:PersonName'), Q\Ubl('cbc:Name')]
        public ?string $name = null,
        #[Q\Bt('BT-42'), Q\Cardinality('0..1'), Q\Cii('ram:TelephoneUniversalCommunication/ram:CompleteNumber'), Q\Ubl('cbc:Telephone')]
        public ?string $phone = null,
        #[Q\Bt('BT-43'), Q\Cardinality('0..1'), Q\Cii('ram:EmailURIUniversalCommunication/ram:URIID'), Q\Ubl('cbc:ElectronicMail')]
        public ?string $email = null,
    ) {}
}
