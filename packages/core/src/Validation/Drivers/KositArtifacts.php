<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Drivers;

use Closure;
use Quittio\Core\Support\Process;
use RuntimeException;
use ZipArchive;

/**
 * Downloads the KoSIT validator and the XRechnung validator configuration (level L5) and
 * compiles the Peppol BIS Billing 3.0 Schematron to XSLT with the Saxon bundled in the
 * validator (requires Java).
 */
final class KositArtifacts
{
    public const string VALIDATOR_VERSION = '1.6.3';

    public const string CONFIGURATION_TAG = 'v2026-08-31';

    public const string CONFIGURATION_FILE = 'xrechnung-3.0.2-validator-configuration-2026-08-31.zip';

    public const string PEPPOL_TAG = 'v3.0.20';

    public const string SCHXSLT_VERSION = '1.10.1';

    /** @var Closure(list<string>, string, int): array{exitCode: int, stdout: string, stderr: string} */
    private Closure $run;

    /** @var Closure(string): string */
    private Closure $fetch;

    /**
     * @param  (Closure(string): string)|null  $fetch  returns the body of a URL
     * @param  (Closure(list<string>, string, int): array{exitCode: int, stdout: string, stderr: string})|null  $run  runs a process
     */
    public function __construct(?Closure $fetch = null, ?Closure $run = null)
    {
        $this->fetch = $fetch ?? self::httpGet(...);
        $this->run = $run ?? Process::run(...);
    }

    /**
     * Scenario file for Peppol BIS Billing 3.0; the repository is the download target.
     */
    public static function peppolScenarios(): string
    {
        return dirname(__DIR__, 3).'/resources/kosit/peppol-scenarios.xml';
    }

    public static function peppolUrl(string $tag = self::PEPPOL_TAG): string
    {
        return sprintf('https://raw.githubusercontent.com/OpenPEPPOL/peppol-bis-invoice-3/%s/rules/sch/PEPPOL-EN16931-UBL.sch', $tag);
    }

    public static function schxsltUrl(string $version = self::SCHXSLT_VERSION): string
    {
        return sprintf('https://repo1.maven.org/maven2/name/dmaus/schxslt/schxslt/%1$s/schxslt-%1$s.jar', $version);
    }

    /**
     * Compiles the Peppol Schematron into peppol/PEPPOL-EN16931-UBL.xsl below $target.
     * download() must have run before, because Saxon comes with the validator JAR.
     *
     * @return array{scenarios: string, repository: string}
     */
    public function compilePeppol(string $target, string $java = 'java', bool $force = false): array
    {
        $directory = $target.'/peppol';
        $xsl = $directory.'/PEPPOL-EN16931-UBL.xsl';
        $result = ['scenarios' => self::peppolScenarios(), 'repository' => $target];
        if (! $force && is_file($xsl)) {
            return $result;
        }
        if (! is_file($target.'/validator.jar')) {
            throw new RuntimeException('The validator JAR is missing; download the validator first.');
        }
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension is required to unpack SchXslt.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create "%s".', $directory));
        }

        $schematron = $directory.'/PEPPOL-EN16931-UBL.sch';
        file_put_contents($schematron, ($this->fetch)(self::peppolUrl()));

        $jar = $directory.'/schxslt.jar';
        file_put_contents($jar, ($this->fetch)(self::schxsltUrl()));
        $zip = new ZipArchive;
        if ($zip->open($jar) !== true) {
            throw new RuntimeException('The SchXslt archive could not be opened.');
        }
        $zip->extractTo($directory.'/schxslt');
        $zip->close();

        $process = ($this->run)([
            $java, '-cp', $target.'/validator.jar', 'net.sf.saxon.Transform',
            '-s:'.$schematron,
            '-xsl:'.$directory.'/schxslt/xslt/2.0/pipeline-for-svrl.xsl',
            '-o:'.$xsl,
        ], '', 300);
        if ($process['exitCode'] !== 0 || ! is_file($xsl)) {
            throw new RuntimeException('Compiling the Peppol rules failed: '.trim(substr($process['stderr'], -500)));
        }

        return $result;
    }

    public static function validatorUrl(string $version = self::VALIDATOR_VERSION): string
    {
        return sprintf('https://repo1.maven.org/maven2/org/kosit/validator/%1$s/validator-%1$s-standalone.jar', $version);
    }

    public static function configurationUrl(string $tag = self::CONFIGURATION_TAG, string $file = self::CONFIGURATION_FILE): string
    {
        return sprintf('https://github.com/itplr-kosit/validator-configuration-xrechnung/releases/download/%s/%s', $tag, $file);
    }

    /**
     * @return array{jar: string, scenarios: string, repository: string}
     */
    public function download(string $target, bool $force = false): array
    {
        if (! is_dir($target) && ! mkdir($target, 0775, true) && ! is_dir($target)) {
            throw new RuntimeException(sprintf('Unable to create "%s".', $target));
        }

        $jar = $target.'/validator.jar';
        if ($force || ! is_file($jar)) {
            file_put_contents($jar, ($this->fetch)(self::validatorUrl()));
        }

        $repository = $target.'/xrechnung';
        $scenarios = $repository.'/scenarios.xml';
        if ($force || ! is_file($scenarios)) {
            if (! class_exists(ZipArchive::class)) {
                throw new RuntimeException('The zip extension is required to unpack the validator configuration.');
            }
            $archive = $target.'/configuration.zip';
            file_put_contents($archive, ($this->fetch)(self::configurationUrl()));
            $zip = new ZipArchive;
            if ($zip->open($archive) !== true) {
                throw new RuntimeException('The validator configuration archive could not be opened.');
            }
            $zip->extractTo($repository);
            $zip->close();
            unlink($archive);
        }

        if (! is_file($scenarios)) {
            throw new RuntimeException('The validator configuration contains no scenarios.xml.');
        }

        return ['jar' => $jar, 'scenarios' => $scenarios, 'repository' => $repository];
    }

    private static function httpGet(string $url): string
    {
        $context = stream_context_create(['http' => ['timeout' => 120, 'follow_location' => 1, 'user_agent' => 'quittio']]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || $body === '') {
            throw new RuntimeException(sprintf('Download failed: %s', $url));
        }

        return $body;
    }
}
