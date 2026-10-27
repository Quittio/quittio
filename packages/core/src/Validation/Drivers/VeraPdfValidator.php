<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Drivers;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Exceptions\RuntimeException;
use Quittio\Core\Support\Process;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationDriver;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;

/**
 * PDF/A conformance check (level L6) with veraPDF.
 *
 * Modes:
 * - cli:    a locally installed veraPDF binary,
 * - docker: the official image (verapdf/cli), the PDF is passed via stdin.
 */
final class VeraPdfValidator implements ValidationDriver
{
    public const string MODE_CLI = 'cli';

    public const string MODE_DOCKER = 'docker';

    /** @var Closure(list<string>, string, int): array{exitCode: int, stdout: string, stderr: string} */
    private Closure $runner;

    /**
     * @param  (Closure(list<string>, string, int): array{exitCode: int, stdout: string, stderr: string})|null  $runner
     */
    public function __construct(
        private readonly string $mode = self::MODE_DOCKER,
        private readonly string $binary = 'verapdf',
        private readonly string $image = 'verapdf/cli',
        private readonly string $docker = 'docker',
        private readonly string $flavour = '3b',
        private readonly int $timeout = 120,
        private readonly bool $failOnUnavailable = true,
        private readonly int $maxFailuresDisplayed = 5,
        ?Closure $runner = null,
    ) {
        if (! in_array($mode, [self::MODE_CLI, self::MODE_DOCKER], true)) {
            throw new InvalidArgumentException(sprintf('Unknown veraPDF mode "%s".', $mode));
        }

        $this->runner = $runner ?? Process::run(...);
    }

    /**
     * Returns "verapdf", the name of this check in the validation.levels configuration.
     */
    public function name(): string
    {
        return 'verapdf';
    }

    public function levels(): array
    {
        return [ValidationLevel::L6];
    }

    public function supports(ValidationSubject $subject): bool
    {
        return $subject->pdf !== null;
    }

    /**
     * Checks the subject's PDF for PDF/A conformance with veraPDF and returns the findings as level
     * L6. If veraPDF cannot be run, the result holds a VERAPDF-UNAVAILABLE error, or a warning when
     * failOnUnavailable is off.
     */
    public function validate(ValidationSubject $subject): ValidationResult
    {
        return new ValidationResult($this->validatePdf($subject->pdf ?? ''), [ValidationLevel::L6]);
    }

    /**
     * @return list<ValidationMessage>
     */
    public function validatePdf(string $pdf): array
    {
        try {
            $report = $this->run($pdf);
        } catch (\RuntimeException $e) { // not only Quittio's: also failures of PHP functions and libraries
            return [new ValidationMessage(
                severity: $this->failOnUnavailable ? Severity::Error : Severity::Warning,
                code: 'VERAPDF-UNAVAILABLE',
                message: 'veraPDF could not be run: '.$e->getMessage(),
                level: ValidationLevel::L6,
            )];
        }

        return self::parse($report);
    }

    /**
     * Converts a veraPDF machine readable report (MRR) into messages.
     *
     * @return list<ValidationMessage>
     */
    public static function parse(string $report): array
    {
        $document = new DOMDocument;
        if (trim($report) === '' || ! @$document->loadXML($report, LIBXML_NONET)) {
            throw new RuntimeException('The veraPDF report is not valid XML.');
        }

        $xpath = new DOMXPath($document);
        $validation = self::first($xpath, '//validationReport');
        if (! $validation instanceof DOMElement) {
            $exception = self::text($xpath, '//taskException/exceptionMessage');

            return [new ValidationMessage(
                severity: Severity::Error,
                code: 'VERAPDF-NOT-PARSED',
                message: 'veraPDF could not validate the file'.($exception === '' ? '.' : ': '.$exception),
                level: ValidationLevel::L6,
            )];
        }

        $messages = [];
        $rules = $xpath->query('.//rule[@status="failed"]', $validation);
        foreach ($rules === false ? [] : $rules as $rule) {
            if (! $rule instanceof DOMElement) {
                continue;
            }
            $clause = $rule->getAttribute('clause');
            $test = $rule->getAttribute('testNumber');
            $error = self::text($xpath, 'check[1]/errorMessage', $rule);
            $description = self::text($xpath, 'description', $rule);
            $context = self::text($xpath, 'check[1]/context', $rule);

            $messages[] = new ValidationMessage(
                severity: Severity::Error,
                code: sprintf('PDFA-%s-%s', $clause, $test),
                message: $error === '' ? $description : $error,
                path: $context === '' ? null : $context,
                hint: $description === '' ? null : sprintf('%s %s: %s', $rule->getAttribute('specification'), $clause, $description),
                level: ValidationLevel::L6,
            );
        }

        if ($messages === [] && $validation->getAttribute('isCompliant') !== 'true') {
            $messages[] = new ValidationMessage(
                severity: Severity::Error,
                code: 'VERAPDF-NOT-COMPLIANT',
                message: $validation->getAttribute('statement'),
                level: ValidationLevel::L6,
            );
        }

        return $messages;
    }

    private static function first(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?DOMNode
    {
        $nodes = $xpath->query($expression, $context);

        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMNode ? $node : null;
    }

    private static function text(DOMXPath $xpath, string $expression, ?DOMNode $context = null): string
    {
        return trim(self::first($xpath, $expression, $context)->textContent ?? '');
    }

    private function run(string $pdf): string
    {
        $options = ['--flavour', $this->flavour, '--format', 'mrr', '--maxfailuresdisplayed', (string) $this->maxFailuresDisplayed];

        if ($this->mode === self::MODE_DOCKER) {
            $command = [$this->docker, 'run', '--rm', '-i', $this->image];
            foreach ($options as $option) {
                $command[] = $option;
            }

            return $this->report(($this->runner)($command, $pdf, $this->timeout));
        }

        $file = tempnam(sys_get_temp_dir(), 'quittio-verapdf-');
        if ($file === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }

        try {
            file_put_contents($file, $pdf);

            $command = [$this->binary];
            foreach ($options as $option) {
                $command[] = $option;
            }
            $command[] = $file;

            return $this->report(($this->runner)($command, '', $this->timeout));
        } finally {
            @unlink($file);
        }
    }

    /**
     * @param  array{exitCode: int, stdout: string, stderr: string}  $result
     */
    private function report(array $result): string
    {
        $start = strpos($result['stdout'], '<?xml');
        if ($start === false) {
            throw new RuntimeException(sprintf('No report (exit code %d). %s', $result['exitCode'], trim(substr($result['stderr'], -500))));
        }

        return substr($result['stdout'], $start);
    }
}
