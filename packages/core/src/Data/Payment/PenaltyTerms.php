<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Payment;

use Brick\Math\BigDecimal;
use Quittio\Core\Data\Concerns\Immutable;

/**
 * Late payment terms. Structured only in profile EXTENDED, otherwise rendered as text.
 */
final readonly class PenaltyTerms
{
    use Immutable;

    public function __construct(
        public ?int $days = null,
        public ?BigDecimal $percent = null,
        public ?string $text = null,
    ) {}
}
