<?php

declare(strict_types=1);

namespace Quittio\Core\Tests\Support;

use Quittio\Core\Validation\Drivers\KositArtifacts;
use Quittio\Core\Validation\Drivers\KositValidator;

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
        $configurations = [['scenarios' => self::root().'/xrechnung/scenarios.xml', 'repository' => self::root().'/xrechnung']];
        if (is_file(self::root().'/peppol/PEPPOL-EN16931-UBL.xsl')) {
            $configurations[] = ['scenarios' => KositArtifacts::peppolScenarios(), 'repository' => self::root()];
        }

        return new KositValidator(
            mode: KositValidator::MODE_CLI,
            jar: self::root().'/validator.jar',
            configurations: $configurations,
            timeout: 120,
        );
    }
}
