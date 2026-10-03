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

    public function rateOn(Date $date): BigDecimal
    {
        $rate = null;
        foreach ($this->rates as $from => $value) {
            if ($from <= $date->toIsoString()) {
                $rate = $value;
            }
        }

        return BigDecimal::of($rate ?? throw new InvalidArgumentException(sprintf('No base rate is known for %s.', $date->toIsoString())));
    }

    /**
     * Periods with a constant rate between two dates (inclusive start, exclusive end).
     *
     * @return list<array{from: Date, until: Date, rate: BigDecimal}>
     */
    public function periods(Date $from, Date $until): array
    {
        $boundaries = [$from->toIsoString()];
        foreach (array_keys($this->rates) as $change) {
            if ($change > $from->toIsoString() && $change < $until->toIsoString()) {
                $boundaries[] = $change;
            }
        }
        $boundaries[] = $until->toIsoString();

        $periods = [];
        for ($i = 0; $i < count($boundaries) - 1; $i++) {
            $start = Date::parse($boundaries[$i]);
            $periods[] = ['from' => $start, 'until' => Date::parse($boundaries[$i + 1]), 'rate' => $this->rateOn($start)];
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
}
