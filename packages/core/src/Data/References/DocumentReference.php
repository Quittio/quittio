<?php

declare(strict_types=1);

namespace Quittio\Core\Data\References;

use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Support\Date;

/**
 * Reference to a related document on line level or as additional reference (profile EXTENDED),
 * e.g. delivery note, order, contract or receiving advice.
 *
 * $typeCode uses UNTDID 1001 (e.g. 130 invoiced object, 916 additional document) where applicable.
 */
final readonly class DocumentReference
{
    use Immutable;

    public const string TYPE_ORDER = 'order';

    public const string TYPE_DESPATCH_ADVICE = 'despatch_advice';

    public const string TYPE_RECEIVING_ADVICE = 'receiving_advice';

    public const string TYPE_CONTRACT = 'contract';

    public const string TYPE_OTHER = 'other';

    public function __construct(
        public string $id,
        public string $kind = self::TYPE_OTHER,
        public ?string $lineId = null,
        public ?Date $issueDate = null,
        public ?RawCode $typeCode = null,
        public ?string $description = null,
    ) {}
}
