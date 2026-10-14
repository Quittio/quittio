<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Drivers;

use Closure;
use InvalidArgumentException;
use Quittio\Core\Support\Process;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationDriver;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;
use RuntimeException;

/**
 * Official Schematron validation (level L5) with the KoSIT validator.
 *
 * Modes:
 * - daemon: HTTP POST to a running validator (recommended; also for Docker or an own endpoint),
 * - cli:    one JVM per call (slow start, no service needed).
 *
 * Schematron rules are XSLT 2.0, which PHP cannot execute; therefore Java is required.
 */
final class KositValidator implements ValidationDriver
{
    public const string MODE_DAEMON = 'daemon';

    public const string MODE_CLI = 'cli';

    private ?string $lastReport = null;

    /** @var Closure(string, string, int): array{status: int, body: ?string} */
    private Closure $http;

    /**
     * @param  list<array{scenarios: string, repository: string}>  $configurations  cli mode only
     * @param  (Closure(string, string, int): array{status: int, body: ?string})|null  $http
     */
    public function __construct(
        private readonly string $mode = self::MODE_DAEMON,
        private readonly string $url = 'http://127.0.0.1:8081',
        private readonly ?string $jar = null,
        private readonly array $configurations = [],
        private readonly string $java = 'java',
        private readonly int $timeout = 30,
        private readonly bool $failOnUnavailable = true,
        private readonly KositReportParser $parser = new KositReportParser,
        ?Closure $http = null,
    ) {
        if (! in_array($mode, [self::MODE_DAEMON, self::MODE_CLI], true)) {
            throw new InvalidArgumentException(sprintf('Unknown KoSIT mode "%s".', $mode));
        }

        $this->http = $http ?? self::defaultHttp(...);
    }

    public function name(): string
    {
        return 'kosit';
    }

    public function levels(): array
    {
        return [ValidationLevel::L5];
    }

    public function supports(ValidationSubject $subject): bool
    {
        return $subject->xml !== null;
    }

    public function validate(ValidationSubject $subject): ValidationResult
    {
        return new ValidationResult($this->validateXml($subject->xml ?? ''), [ValidationLevel::L5]);
    }

    /**
     * @return list<ValidationMessage>
     */
    public function validateXml(string $xml): array
    {
        $this->lastReport = null;

        try {
            $report = $this->mode === self::MODE_DAEMON ? $this->viaDaemon($xml) : $this->viaCli($xml);
        } catch (RuntimeException $e) {
            return [new ValidationMessage(
                severity: $this->failOnUnavailable ? Severity::Error : Severity::Warning,
                code: 'KOSIT-UNAVAILABLE',
                message: 'The KoSIT validator could not be run: '.$e->getMessage(),
                level: ValidationLevel::L5,
            )];
        }

        $this->lastReport = $report;

        return $this->parser->parse($report)['messages'];
    }

    /**
     * Raw report of the last validation (for archiving as test report).
     */
    public function lastReport(): ?string
    {
        return $this->lastReport;
    }

    private function viaDaemon(string $xml): string
    {
        $response = ($this->http)(rtrim($this->url, '/').'/', $xml, $this->timeout);
        if ($response['body'] === null || ! in_array($response['status'], [200, 406, 422], true)) {
            throw new RuntimeException(sprintf('No report from %s (HTTP status %d).', $this->url, $response['status']));
        }

        return $response['body'];
    }

    private function viaCli(string $xml): string
    {
        if ($this->jar === null || ! is_file($this->jar)) {
            throw new RuntimeException('The validator JAR is not configured or does not exist.');
        }
        if ($this->configurations === []) {
            throw new RuntimeException('No validator configuration (scenarios and repository) is configured.');
        }

        $directory = sys_get_temp_dir().'/quittio-kosit-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create a temporary directory.');
        }
        $input = $directory.'/document.xml';
        file_put_contents($input, $xml);

        $command = [$this->java, '-jar', $this->jar];
        foreach ($this->configurations as $configuration) {
            $command[] = '-s';
            $command[] = $configuration['scenarios'];
            $command[] = '-r';
            $command[] = $configuration['repository'];
        }
        array_push($command, '-o', $directory, $input);

        try {
            $process = Process::open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $start = time();
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $output = '';
            while (proc_get_status($process)['running']) {
                $output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
                if (time() - $start > $this->timeout) {
                    proc_terminate($process);
                    throw new RuntimeException(sprintf('The validator did not finish within %d seconds.', $this->timeout));
                }
                usleep(50_000);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $report = $directory.'/document-report.xml';
            if (! is_file($report)) {
                throw new RuntimeException('The validator produced no report. Output: '.trim(substr($output, -500)));
            }

            return (string) file_get_contents($report);
        } finally {
            $files = glob($directory.'/*');
            foreach ($files === false ? [] : $files as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    /**
     * @return array{status: int, body: ?string}
     */
    private static function defaultHttp(string $url, string $body, int $timeout): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/xml\r\n",
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);

        $stream = @fopen($url, 'r', false, $context);
        if ($stream === false) {
            return ['status' => 0, 'body' => null];
        }

        try {
            $response = stream_get_contents($stream);
            $meta = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }

        $status = 0;
        $headers = $meta['wrapper_data'] ?? [];
        foreach (is_array($headers) ? $headers : [] as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => $response === false ? null : $response];
    }
}
