<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Drivers;

use Closure;
use Quittio\Core\Exceptions\RuntimeException;
use Quittio\Core\Support\Process;
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

    /**
     * SHA-256 of the pinned downloads. The default download checks them, because the validator JAR
     * is run with java and release tags can be moved; update them together with the versions.
     *
     * @var array<string, string>
     */
    public const array SHA256 = [
        'https://repo1.maven.org/maven2/org/kosit/validator/1.6.3/validator-1.6.3-standalone.jar' => '799e64befca97d4080e03608c80b85dd5a5ecc5f4ae4f35d1116ec2855b9a7c9',
        'https://github.com/itplr-kosit/validator-configuration-xrechnung/releases/download/v2026-08-31/xrechnung-3.0.2-validator-configuration-2026-08-31.zip' => '2530cd107c414511c5d0462ec10f886910395abfca820db82e83d70bf01221a8',
        'https://raw.githubusercontent.com/OpenPEPPOL/peppol-bis-invoice-3/v3.0.20/rules/sch/PEPPOL-EN16931-UBL.sch' => '5ddf3a2f6633147b20b7805df9902d825af1a984f10014fcee186d4170364b1d',
        'https://repo1.maven.org/maven2/name/dmaus/schxslt/schxslt/1.10.1/schxslt-1.10.1.jar' => '4f4f21edab7b37f96ad59ae12a344d3510f1092ac46b6d81a4efa0120b73cb58',
    ];

    /** @var Closure(list<string>, string, int): array{exitCode: int, stdout: string, stderr: string} */
    private Closure $run;

    /** @var Closure(string): string */
    private Closure $fetch;

    /**
     * @param  (Closure(string): string)|null  $fetch  returns the body of a URL; the default checks it against SHA256
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
        $expected = self::SHA256[$url] ?? throw new RuntimeException(sprintf('No checksum is known for %s; downloads are only accepted with a pinned SHA-256.', $url));

        $context = stream_context_create(['http' => ['timeout' => 120, 'follow_location' => 1, 'user_agent' => 'quittio']]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || $body === '') {
            throw new RuntimeException(sprintf('Download failed: %s', $url));
        }
        if (! hash_equals($expected, hash('sha256', $body))) {
            throw new RuntimeException(sprintf('The download of %s does not match its pinned SHA-256 checksum; it was discarded.', $url));
        }

        return $body;
    }
}
