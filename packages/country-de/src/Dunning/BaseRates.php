<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Dunning;

use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Date;

/**
 * Base rates (section 247 BGB) of the application in addition to the table shipped with
 * Quittio, e.g. rates published after the release or fetched with BundesbankBaseRates.
 * DefaultInterest uses table() unless it is given a table of its own. In Laravel the
 * GermanyServiceProvider registers the configured and the fetched rates.
 */
final class BaseRates
{
    /** @var array<string, string> ISO date => rate in percent */
    private static array $rates = [];

    /**
     * Adds or replaces rates. Later registrations win for the same date.
     *
     * @param  array<array-key, mixed>  $rates  ISO date (1 January or 1 July) => rate in percent, e.g. ['2027-01-01' => '1.27']
     */
    public static function register(array $rates): void
    {
        foreach (self::normalise($rates) as $date => $rate) {
            self::$rates[$date] = $rate;
        }
    }

    /**
     * The shipped table with the registered rates.
     */
    public static function table(): BaseRateTable
    {
        return new BaseRateTable([...BaseRateTable::PUBLISHED, ...self::$rates]);
    }

    /**
     * @return array<string, string>
     */
    public static function registered(): array
    {
        return self::$rates;
    }

    public static function reset(): void
    {
        self::$rates = [];
    }

    /**
     * Checks and normalises rates: the base rate changes on 1 January and 1 July only
     * (section 247 (1) sentence 2 BGB), values are decimal percentages.
     *
     * @param  array<array-key, mixed>  $rates
     * @return array<string, string>
     */
    public static function normalise(array $rates): array
    {
        $normalised = [];
        foreach ($rates as $date => $rate) {
            $iso = Date::parse((string) $date)->toIsoString();
            if (! str_ends_with($iso, '-01-01') && ! str_ends_with($iso, '-07-01')) {
                throw new InvalidArgumentException(sprintf('The base rate changes on 1 January and 1 July only; "%s" is neither.', $date));
            }
            $value = is_int($rate) ? (string) $rate : $rate;
            if (! is_string($value) || preg_match('/^-?\d{1,2}(\.\d{1,2})?$/', trim($value)) !== 1) {
                throw new InvalidArgumentException(sprintf('The base rate for %s must be a percentage such as "1.27", %s given.', $iso, get_debug_type($rate)));
            }
            $normalised[$iso] = trim($value);
        }
        ksort($normalised);

        return $normalised;
    }
}
