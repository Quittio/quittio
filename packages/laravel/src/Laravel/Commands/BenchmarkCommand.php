<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use Quittio\Core\Testing\Scenarios;
use Quittio\Laravel\QuittioManager;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'quittio:benchmark')]
final class BenchmarkCommand extends QuittioCommand
{
    protected $signature = 'quittio:benchmark
        {--count=20 : Number of runs per format}
        {--scenario=simple : Sample scenario}
        {--format=* : Formats, default: cii and zugferd}';

    protected $description = 'Measure calculation, XML and PDF performance';

    public function handle(QuittioManager $quittio): int
    {
        $count = max(1, (int) ($this->stringOption('count') ?? '20'));
        $scenario = $this->stringOption('scenario') ?? 'simple';
        $formats = array_values(array_filter((array) $this->option('format'), is_string(...)));
        $formats = $formats === [] ? ['cii', 'zugferd'] : $formats;

        if (! in_array($scenario, Scenarios::names(), true)) {
            $this->components->error(sprintf('Unknown scenario "%s".', $scenario));

            return self::FAILURE;
        }

        $invoice = Scenarios::make($scenario)->build();
        $rows = [];
        foreach ($formats as $format) {
            // The first run warms up fonts and caches and is not measured.
            $quittio->make($invoice)->format($format)->validate(throwOnError: false)->render();

            $times = [];
            for ($i = 0; $i < $count; $i++) {
                $start = hrtime(true);
                $quittio->make($invoice)->format($format)->validate(throwOnError: false)->render();
                $times[] = (hrtime(true) - $start) / 1e6;
            }
            sort($times);
            $rows[] = [
                $format,
                (string) $count,
                sprintf('%.1f', array_sum($times) / count($times)),
                sprintf('%.1f', $times[0]),
                sprintf('%.1f', $times[(int) floor(0.95 * (count($times) - 1))]),
                sprintf('%.1f', $times[count($times) - 1]),
            ];
        }

        $this->table(['Format', 'Runs', 'Avg ms', 'Min ms', 'P95 ms', 'Max ms'], $rows);
        $this->line(sprintf('Peak memory: %.1f MB', memory_get_peak_usage(true) / 1024 / 1024));

        return self::SUCCESS;
    }
}
