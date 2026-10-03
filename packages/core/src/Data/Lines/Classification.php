<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Lines;

use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Enums\ItemClassificationScheme;

/**
 * Item classification identifier (BT-158) with scheme (BT-158-1) and scheme version (BT-158-2),
 * e.g. eCl@ss, UNSPSC, CPV or customs tariff numbers.
 */
final readonly class Classification
{
    use Immutable;

    public function __construct(
        public string $code,
        public ItemClassificationScheme|RawCode $listId,
        public ?string $listVersion = null,
    ) {}
}
