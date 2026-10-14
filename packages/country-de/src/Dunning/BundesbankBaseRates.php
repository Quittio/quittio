<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Dunning;

use Closure;
use RuntimeException;

/**
 * Fetches the base rate according to section 247 BGB from the statistics API of the Deutsche
 * Bundesbank (time series BBIN1.M.DE.BBK.BBKBAS2.EUR.ME, formerly SU0115). The series has a
 * value per month; the rate changes on 1 January and 1 July, so the values of January and
 * July are the table entries. A new rate appears once the Bundesbank has published the value
 * of that month.
 */
final readonly class BundesbankBaseRates
{
    public const string URL = 'https://api.statistiken.bundesbank.de/rest/data/BBIN1/M.DE.BBK.BBKBAS2.EUR.ME?format=csv&lang=de&startPeriod=%s';

    /** @var Closure(string, int): string */
    private Closure $http;

    /**
     * @param  (Closure(string $url, int $timeout): string)|null  $http  returns the response body, throws on failure
     */
    public function __construct(?Closure $http = null, private int $timeout = 15)
    {
        $this->http = $http ?? self::get(...);
    }

    /**
     * @param  string  $since  first month (YYYY-MM)
     * @return array<string, string> ISO date => rate in percent, e.g. ['2026-07-01' => '1.52']
     */
    public function fetch(string $since = '2002-01'): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $since) !== 1) {
            throw new RuntimeException(sprintf('The first month must be given as YYYY-MM, "%s" given.', $since));
        }
        $rates = self::parse(($this->http)(sprintf(self::URL, $since), $this->timeout));
        if ($rates === []) {
            throw new RuntimeException('The Bundesbank returned no base rates.');
        }

        return $rates;
    }

    /**
     * Reads the January and July values of the CSV download ("2026-07;1,52;…").
     *
     * @return array<string, string>
     */
    public static function parse(string $csv): array
    {
        $rates = [];
        $lines = preg_split('/\R/', $csv);
        foreach ($lines === false ? [] : $lines as $line) {
            if (preg_match('/^"?(\d{4})-(01|07)"?;"?(-?\d+(?:,\d+)?)"?(?:;|$)/', trim($line), $match) === 1) {
                $rates[sprintf('%s-%s-01', $match[1], $match[2])] = str_replace(',', '.', $match[3]);
            }
        }
        ksort($rates);

        return BaseRates::normalise($rates);
    }

    private static function get(string $url, int $timeout): string
    {
        // Without ignore_errors an HTTP error status makes file_get_contents() return false.
        $context = stream_context_create(['http' => [
            'timeout' => $timeout,
            'header' => "Accept: text/csv\r\nUser-Agent: Quittio\r\n",
        ]]);
        set_error_handler(static fn (): bool => true);
        try {
            $body = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }
        if ($body === false || $body === '') {
            throw new RuntimeException('The Bundesbank statistics API could not be reached or returned an error.');
        }

        return $body;
    }
}
