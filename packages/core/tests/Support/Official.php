<?php

declare(strict_types=1);

namespace Quittio\Core\Tests\Support;

use Quittio\Core\Validation\Drivers\KositArtifacts;
use Quittio\Core\Validation\Drivers\KositReportParser;
use Quittio\Core\Validation\Drivers\KositValidator;
use Quittio\Core\Validation\ValidationMessage;

/**
 * Access to the official validators downloaded by tools/schemas/download.sh.
 * Tests that need them are skipped when Java or the artifacts are missing.
 */
final class Official
{
    public static function root(): string
    {
        return dirname(__DIR__, 4).'/tools/validators';
    }

    public static function available(): bool
    {
        if (! is_file(self::root().'/validator.jar') || ! is_file(self::root().'/xrechnung/scenarios.xml')) {
            return false;
        }

        exec('java -version 2>&1', $output, $code);

        return $code === 0;
    }

    public static function kosit(): KositValidator
    {
        return new KositValidator(
            mode: KositValidator::MODE_CLI,
            jar: self::root().'/validator.jar',
            configurations: self::configurations(),
            timeout: 120,
        );
    }

    /**
     * Validates several documents with one run of the validator (one Java start instead of one
     * per document).
     *
     * @param  array<string, string>  $documents  name => XML
     * @return array<string, list<ValidationMessage>> name => findings
     */
    public static function kositAll(array $documents): array
    {
        $directory = sys_get_temp_dir().'/quittio-kosit-batch-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $files = [];
        foreach (array_keys($documents) as $index => $name) {
            $files[$name] = $directory.'/document-'.$index.'.xml';
            file_put_contents($files[$name], $documents[$name]);
        }

        $command = ['java', '-jar', self::root().'/validator.jar'];
        foreach (self::configurations() as $configuration) {
            array_push($command, '-s', $configuration['scenarios'], '-r', $configuration['repository']);
        }
        array_push($command, '-o', $directory, ...array_values($files));
        exec(implode(' ', array_map(escapeshellarg(...), $command)).' 2>&1', $output);

        $parser = new KositReportParser;
        $results = [];
        foreach ($files as $name => $file) {
            $report = substr($file, 0, -4).'-report.xml';
            $results[$name] = is_file($report) ? $parser->parse((string) file_get_contents($report))['messages'] : [];
        }
        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);

        return $results;
    }

    /**
     * @return list<array{scenarios: string, repository: string}>
     */
    private static function configurations(): array
    {
        $configurations = [['scenarios' => self::root().'/xrechnung/scenarios.xml', 'repository' => self::root().'/xrechnung']];
        if (is_file(self::root().'/peppol/PEPPOL-EN16931-UBL.xsl')) {
            $configurations[] = ['scenarios' => KositArtifacts::peppolScenarios(), 'repository' => self::root()];
        }

        return $configurations;
    }
}
