<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Tests\Support;

use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Country\DE\CountryDE;

final class German
{
    public static function profiles(): ProfileRegistry
    {
        $registry = ProfileRegistry::withDefaults();
        foreach ((new CountryDE)->profiles() as $profile) {
            $registry->register($profile);
        }

        return $registry;
    }

    public static function reader(): InvoiceReader
    {
        return new InvoiceReader(profiles: self::profiles());
    }

    public static function testSuite(): string
    {
        return dirname(__DIR__).'/Fixtures/xrechnung-testsuite/instances';
    }

    /**
     * Official XRechnung test files: standard, extension and the technical cases of the CIUS and of XRechnung CVD.
     *
     * @return array<string, array{0: string}>
     */
    public static function testSuiteFiles(): array
    {
        $files = [];
        foreach (['standard', 'extension', 'technical-cases/cius', 'technical-cases/cvd'] as $directory) {
            foreach (glob(self::testSuite().'/'.$directory.'/*.xml') ?: [] as $file) {
                $files[$directory.'/'.basename($file)] = [$file];
            }
        }
        ksort($files);

        return $files;
    }
}
