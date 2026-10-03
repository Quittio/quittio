<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Extensions;

use Brick\Money\Money;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Support\Date;

/**
 * One of several payment terms with its own due date (profile EXTENDED), e.g. instalments.
 */
final readonly class Installment
{
    use Immutable;

    public function __construct(
        public Date $dueDate,
        public ?Money $amount = null,
        public ?string $description = null,
    ) {}
}
