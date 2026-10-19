<?php

declare(strict_types=1);

namespace Quittio\Country\BE\Dunning;

use Brick\Math\BigDecimal;
use Quittio\Core\Dunning\ReferenceRates;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Date;

/**
 * The reference rate of the late payment law of 2 August 2002: the main refinancing rate of the
 * ECB on the first day of the half-year, which with the 8 points of the law gives the published
 * rate rounded up to the next half point. The table holds the rates the points are added to,
 * so that the sum is the rate published by the FPS Finance (e.g. 2.50 + 8 = 10.50 % for 2026).
 */
final readonly class ReferenceRateTable implements ReferenceRates
{
    /** Rates in percent by the first day of the half-year, the published rate minus 8 points. */
    public const array PUBLISHED = [
        '2023-01-01' => '2.50',
        '2023-07-01' => '4.00',
        '2024-01-01' => '4.50',
        '2024-07-01' => '4.50',
        '2025-01-01' => '3.50',
        '2025-07-01' => '2.50',
        '2026-01-01' => '2.50',
        '2026-07-01' => '2.50',
    ];

    /** @var array<string, string> */
    private array $rates;

    /**
     * @param  array<string, string>  $rates  ISO date (1 January or 1 July) => rate in percent
     */
    public function __construct(array $rates = self::PUBLISHED)
    {
        ksort($rates);
        $this->rates = $rates;
    }

    /**
     * A copy with the rate of a half-year, e.g. a newly published one (published rate minus 8 points).
     */
    public function withRate(string $from, string $rate): self
    {
        return new self([...$this->rates, Date::parse($from)->toIsoString() => $rate]);
    }

    public function rateOn(Date $date): BigDecimal
    {
        $first = (string) array_key_first($this->rates);
        if ($date->toIsoString() < $first || ! $date->isBefore($this->validUntil())) {
            throw new InvalidArgumentException(sprintf('No Belgian reference rate is known for %s. Add it with withRate().', $date->toIsoString()));
        }
        $rate = $this->rates[$first];
        foreach ($this->rates as $from => $value) {
            if ($from <= $date->toIsoString()) {
                $rate = $value;
            }
        }

        return BigDecimal::of($rate);
    }

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
            $rate = $this->rateOn($start);
            $last = $periods === [] ? null : $periods[count($periods) - 1];
            if ($last !== null && $last['rate']->isEqualTo($rate)) {
                $periods[count($periods) - 1]['until'] = Date::parse($boundaries[$i + 1]);

                continue;
            }
            $periods[] = ['from' => $start, 'until' => Date::parse($boundaries[$i + 1]), 'rate' => $rate];
        }
        if ($until->isAfter($this->validUntil())) {
            throw new InvalidArgumentException(sprintf('No Belgian reference rate is known after %s. Add it with withRate().', $this->validUntil()->addDays(-1)->toIsoString()));
        }

        return $periods;
    }

    /**
     * The first day without a known rate: the half-year after the last entry.
     */
    public function validUntil(): Date
    {
        $last = Date::parse((string) array_key_last($this->rates));

        return $last->month < 7 ? Date::of($last->year, 7, 1) : Date::of($last->year + 1, 1, 1);
    }
}
