<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Laravel;

use Brick\Math\BigDecimal;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Quittio\Country\DE\Dunning\BaseRates;
use Quittio\Country\DE\Dunning\BaseRateTable;
use Quittio\Country\DE\Dunning\BundesbankBaseRates;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Fetches the base rates (section 247 BGB) from the Deutsche Bundesbank and stores them in
 * quittio-de.base_rate_file, so that default interest can be calculated for periods after the
 * table shipped with Quittio. Schedule it, e.g. weekly: it writes the file only when the
 * Bundesbank has published something new.
 *
 * @internal
 */
#[AsCommand(name: 'quittio:base-rate:update')]
final class UpdateBaseRateCommand extends Command
{
    protected $signature = 'quittio:base-rate:update
        {--dry-run : Show the rates without writing the file}';

    protected $description = 'Fetch the Basiszinssatz (§ 247 BGB) from the Deutsche Bundesbank';

    public function handle(Repository $config, BundesbankBaseRates $bundesbank): int
    {
        $file = $config->get('quittio-de.base_rate_file');
        $file = is_string($file) && $file !== '' ? $file : null;
        if (! $this->option('dry-run') && $file === null) {
            $this->components->error('quittio-de.base_rate_file is not set: there is nowhere to store the rates. Set it or add the rates to quittio-de.base_rates.');

            return self::FAILURE;
        }

        try {
            $fetched = $bundesbank->fetch();
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $known = BaseRates::table();
        $new = array_diff_key($fetched, BaseRateTable::PUBLISHED);
        $differing = array_filter(
            array_intersect_key($fetched, BaseRateTable::PUBLISHED),
            static fn (string $rate, string $date): bool => ! BigDecimal::of($rate)->isEqualTo(BaseRateTable::PUBLISHED[$date]),
            ARRAY_FILTER_USE_BOTH,
        );
        foreach ($differing as $date => $rate) {
            $this->components->warn(sprintf('The Bundesbank reports %s %% from %s, Quittio ships %s %%; the Bundesbank value is used.', $rate, $date, BaseRateTable::PUBLISHED[$date]));
        }

        $latest = (string) array_key_last($fetched);
        $shippedUntil = (string) array_key_last(BaseRateTable::PUBLISHED);
        $later = array_filter($new, static fn (string $date): bool => $date > $shippedUntil, ARRAY_FILTER_USE_KEY);
        $this->components->info(sprintf('Latest base rate: %s %% from %s.', $fetched[$latest], $latest));
        if ($later !== []) {
            $this->table(['From', 'Rate (%)'], array_map(static fn (string $date, string $rate): array => [$date, $rate], array_keys($later), $later));
        } else {
            $this->line(sprintf('  No rate after the table shipped with Quittio (last change %s); the table is valid until %s.', $shippedUntil, $known->validUntil()->toIsoString()));
        }
        if (count($new) > count($later)) {
            $this->line(sprintf('  Plus %d earlier rate(s), for default interest of older claims.', count($new) - count($later)));
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        if ($file === null) {
            return self::FAILURE;
        }
        $stored = is_file($file) ? BaseRates::normalise(GermanyServiceProvider::readFile($file)) : [];
        $rates = [...$new, ...$differing];
        ksort($rates);
        if ($rates === $stored) {
            $this->components->info('No new rates: '.$file.' is up to date.');

            return self::SUCCESS;
        }

        $directory = dirname($file);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->components->error(sprintf('The directory %s could not be created.', $directory));

            return self::FAILURE;
        }
        if (@file_put_contents($file, json_encode($rates, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n", LOCK_EX) === false) {
            $this->components->error(sprintf('%s could not be written.', $file));

            return self::FAILURE;
        }
        BaseRates::register($rates);
        $this->components->info(sprintf('Stored %d rate(s) in %s.', count($rates), $file));

        return self::SUCCESS;
    }
}
