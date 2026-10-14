<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Dunning;

use Brick\Math\BigDecimal;
use InvalidArgumentException;
use Quittio\Core\Support\Date;

/**
 * Basiszinssatz according to section 247 BGB, published by the Deutsche Bundesbank and
 * changed on 1 January and 1 July. Rates after the last entry must be added with
 * withRate() (or the configuration of the application) when they are published.
 */
final readonly class BaseRateTable
{
    /** Known rates in percent, keyed by the first day of validity. */
    public const array PUBLISHED = [
        '2023-01-01' => '1.62',
        '2023-07-01' => '3.12',
        '2024-01-01' => '3.62',
        '2024-07-01' => '3.37',
        '2025-01-01' => '2.27',
        '2025-07-01' => '1.27',
        '2026-01-01' => '1.27',
        '2026-07-01' => '1.52',
    ];

    /** @var array<string, string> sorted by date */
    private array $rates;

    /**
     * @param  array<string, string>  $rates  ISO date => rate in percent
     */
    public function __construct(array $rates = self::PUBLISHED)
    {
        ksort($rates);
        $this->rates = $rates;
    }

    public function withRate(Date|string $from, string $rate): self
    {
        $key = $from instanceof Date ? $from->toIsoString() : Date::parse($from)->toIsoString();

        return new self([...$this->rates, $key => $rate]);
    }

    /**
     * The base rate changes on 1 January and 1 July (section 247 (1) sentence 2 BGB). Dates from the
     * next scheduled change after the last known rate on are unknown and rejected.
     */
    public function rateOn(Date $date): BigDecimal
    {
        if (! $date->isBefore($this->validUntil())) {
            throw new InvalidArgumentException(sprintf('No base rate is known for %s: the table ends before %s. Add the published rate with withRate().', $date->toIsoString(), $this->validUntil()->toIsoString()));
        }

        $rate = null;
        foreach ($this->rates as $from => $value) {
            if ($from <= $date->toIsoString()) {
                $rate = $value;
            }
        }

        return BigDecimal::of($rate ?? throw new InvalidArgumentException(sprintf('No base rate is known for %s.', $date->toIsoString())));
    }

    /**
     * Periods with a constant rate between two dates (inclusive start, exclusive end). A table
     * entry that keeps the rate (e.g. 2026-01-01) does not start a new period.
     *
     * @return list<array{from: Date, until: Date, rate: BigDecimal}>
     */
    public function periods(Date $from, Date $until): array
    {
        if ($until->isAfter($this->validUntil())) {
            throw new InvalidArgumentException(sprintf('No base rate is known after %s. Add the published rate with withRate().', $this->validUntil()->addDays(-1)->toIsoString()));
        }

        $boundaries = [$from->toIsoString()];
        foreach (array_keys($this->rates) as $change) {
            if ($change > $from->toIsoString() && $change < $until->toIsoString()) {
                $boundaries[] = $change;
            }
        }
        $boundaries[] = $until->toIsoString();

        $periods = [];
        $current = null;
        for ($i = 0; $i < count($boundaries) - 1; $i++) {
            $start = Date::parse($boundaries[$i]);
            $end = Date::parse($boundaries[$i + 1]);
            $rate = $this->rateOn($start);
            if ($current !== null && $current['rate']->isEqualTo($rate)) {
                $current = ['from' => $current['from'], 'until' => $end, 'rate' => $rate];

                continue;
            }
            if ($current !== null) {
                $periods[] = $current;
            }
            $current = ['from' => $start, 'until' => $end, 'rate' => $rate];
        }
        if ($current !== null) {
            $periods[] = $current;
        }

        return $periods;
    }

    /**
     * Date of the most recent known rate, e.g. to warn when the table is outdated.
     */
    public function lastChange(): Date
    {
        return Date::parse((string) array_key_last($this->rates));
    }

    /**
     * First day for which the table has no rate: the next 1 January or 1 July after the last change.
     */
    public function validUntil(): Date
    {
        $last = $this->lastChange();
        $year = (int) $last->format('Y');

        return (int) $last->format('n') < 7 ? Date::of($year, 7, 1) : Date::of($year + 1, 1, 1);
    }
}
