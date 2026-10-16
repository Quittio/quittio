<?php

declare(strict_types=1);

namespace Quittio\Core\Support;

use Quittio\Core\Exceptions\InvalidArgumentException;

/**
 * Values that change on dates, e.g. tax rates or reference interest rates: every value holds
 * from its date until the next change.
 */
final readonly class DatedValues
{
    /** @var array<string, string> ISO date => value, sorted by date */
    private array $values;

    /**
     * @param  array<string, string>  $values  ISO date (YYYY-MM-DD) => value from that date on
     *
     * @throws InvalidArgumentException for a key that is no ISO date
     */
    public function __construct(array $values)
    {
        $sorted = [];
        foreach ($values as $date => $value) {
            $sorted[Date::fromIsoDate((string) $date)->toIsoString()] = $value;
        }
        ksort($sorted, SORT_STRING);
        $this->values = $sorted;
    }

    /**
     * The value that holds on the date; null before the first change.
     */
    public function on(Date $date): ?string
    {
        $value = null;
        foreach ($this->values as $from => $candidate) {
            if ($from > $date->toIsoString()) {
                break;
            }
            $value = $candidate;
        }

        return $value;
    }

    /**
     * A copy with a value that holds from $from on; an existing value of that date is replaced.
     */
    public function withValue(Date|string $from, string $value): self
    {
        $date = $from instanceof Date ? $from : Date::fromIsoDate($from);

        return new self([...$this->values, $date->toIsoString() => $value]);
    }

    /**
     * The periods of constant value between two dates (both included), in order; days before
     * the first change are left out.
     *
     * @return list<array{from: Date, until: Date, value: string}>
     */
    public function periods(Date $from, Date $until): array
    {
        if ($until->isBefore($from)) {
            return [];
        }

        $periods = [];
        $dates = array_keys($this->values);
        foreach ($dates as $index => $start) {
            $next = $dates[$index + 1] ?? null;
            $periodFrom = Date::fromIsoDate($start);
            $periodUntil = $next === null ? null : Date::fromIsoDate($next)->addDays(-1);
            if (($periodUntil !== null && $periodUntil->isBefore($from)) || $periodFrom->isAfter($until)) {
                continue;
            }
            $periods[] = [
                'from' => $periodFrom->isBefore($from) ? $from : $periodFrom,
                'until' => $periodUntil === null || $periodUntil->isAfter($until) ? $until : $periodUntil,
                'value' => $this->values[$start],
            ];
        }

        return $periods;
    }

    /**
     * Every change: ISO date => value, sorted by date.
     *
     * @return array<string, string>
     */
    public function changes(): array
    {
        return $this->values;
    }

    /**
     * Date of the last change; null without values.
     */
    public function lastChange(): ?Date
    {
        $last = array_key_last($this->values);

        return $last === null ? null : Date::fromIsoDate($last);
    }
}
