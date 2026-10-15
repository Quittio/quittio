<?php

declare(strict_types=1);

namespace Quittio\Core\Support;

use Quittio\Core\Exceptions\RuntimeException;

/**
 * Minimal external process runner with stdin, timeout and captured output.
 */
final class Process
{
    /**
     * @param  list<string>  $command
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public static function run(array $command, ?string $input = null, int $timeout = 60): array
    {
        $process = self::open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $pending = $input ?? '';
        if ($pending === '') {
            fclose($pipes[0]);
        } else {
            stream_set_blocking($pipes[0], false);
        }

        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        $exitCode = -1;

        try {
            while (true) {
                if ($pending !== '') {
                    $written = fwrite($pipes[0], $pending);
                    if ($written === false) {
                        $pending = '';
                    } else {
                        $pending = substr($pending, $written);
                    }
                    if ($pending === '') {
                        fclose($pipes[0]);
                    }
                }

                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);

                $status = proc_get_status($process);
                if (! $status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }
                if (microtime(true) - $start > $timeout) {
                    proc_terminate($process);
                    throw new RuntimeException(sprintf('"%s" did not finish within %d seconds.', $command[0] ?? '', $timeout));
                }
                usleep(20_000);
            }

            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
        } finally {
            if ($pending !== '' && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * proc_open() that reports a program that cannot be started (e.g. java, verapdf or docker
     * not installed) as RuntimeException, also where PHP warnings become exceptions (Laravel),
     * so that validators can report themselves as unavailable.
     *
     * @param  list<string>  $command
     * @param  array<int, list<string>>  $descriptors
     * @param  array<int, resource>|null  $pipes
     *
     * @param-out array<int, resource> $pipes
     *
     * @return resource
     */
    public static function open(array $command, array $descriptors, ?array &$pipes)
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $process = proc_open($command, $descriptors, $pipes);
        } finally {
            restore_error_handler();
        }
        if (! is_resource($process)) {
            throw new RuntimeException(sprintf('Unable to start "%s"%s.', $command[0] ?? '', $warning === null ? '' : ': '.$warning));
        }

        return $process;
    }
}
