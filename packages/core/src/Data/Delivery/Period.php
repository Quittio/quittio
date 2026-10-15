<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Delivery;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Date;

/**
 * Invoicing period (BG-14: BT-73, BT-74) or invoice line period (BG-26: BT-134, BT-135).
 * At least one of start or end must be set; the end must not lie before the start.
 */
final readonly class Period
{
    use Immutable;

    public function __construct(
        public ?Date $start = null,
        public ?Date $end = null,
    ) {
        if ($start === null && $end === null) {
            throw new InvalidArgumentException('A period requires a start date, an end date or both.');
        }
    }

    public static function between(Date|string|null $start, Date|string|null $end): self
    {
        return new self(
            $start === null ? null : Date::parse($start),
            $end === null ? null : Date::parse($end),
        );
    }

    public function isValid(): bool
    {
        return $this->start === null || $this->end === null || ! $this->end->isBefore($this->start);
    }
}
