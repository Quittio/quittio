<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Lines;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;

/**
 * Item attribute (BG-32), e.g. colour, size, vehicle identification number.
 */
final readonly class ItemAttribute
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-160'), Q\Cardinality('1'), Q\Cii('ram:ApplicableProductCharacteristic/ram:Description'), Q\Ubl('cac:AdditionalItemProperty/cbc:Name')]
        public string $name,
        #[Q\Bt('BT-161'), Q\Cardinality('1'), Q\Cii('ram:ApplicableProductCharacteristic/ram:Value'), Q\Ubl('cac:AdditionalItemProperty/cbc:Value')]
        public string $value,
    ) {}
}
