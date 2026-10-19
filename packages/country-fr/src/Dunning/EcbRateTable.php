<?php

declare(strict_types=1);

namespace Quittio\Country\FR\Dunning;

use Brick\Math\BigDecimal;
use Quittio\Core\Dunning\ReferenceRates;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Date;

/**
 * The rate of the main refinancing operations of the ECB on the first day of the half-year, the
 * reference of the late payment penalties between businesses (art. L441-10 Code de commerce:
 * ECB rate plus 10 points, e.g. 12.40 % in the second half of 2026).
 */
final readonly class EcbRateTable implements ReferenceRates
{
    /** Rates in percent by the first day of the half-year. */
    public const array PUBLISHED = [
        '2023-01-01' => '2.50',
        '2023-07-01' => '4.00',
        '2024-01-01' => '4.50',
        '2024-07-01' => '4.25',
        '2025-01-01' => '3.15',
        '2025-07-01' => '2.15',
        '2026-01-01' => '2.15',
        '2026-07-01' => '2.40',
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
     * A copy with the rate of a half-year, e.g. a newly published one.
     */
    public function withRate(string $from, string $rate): self
    {
        return new self([...$this->rates, Date::parse($from)->toIsoString() => $rate]);
    }

    public function rateOn(Date $date): BigDecimal
    {
        $first = (string) array_key_first($this->rates);
        if ($date->toIsoString() < $first || ! $date->isBefore($this->validUntil())) {
            throw new InvalidArgumentException(sprintf('No ECB reference rate is known for %s. Add it with withRate().', $date->toIsoString()));
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
        if ($until->isAfter($this->validUntil())) {
            throw new InvalidArgumentException(sprintf('No ECB reference rate is known after %s. Add it with withRate().', $this->validUntil()->addDays(-1)->toIsoString()));
        }
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
