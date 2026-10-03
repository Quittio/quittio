<?php

declare(strict_types=1);

/**
 * Generates the code list files in packages/core/resources/codelists from the
 * official EN 16931 Schematron rules (BR-CL-*), as shipped with the KoSIT
 * validator configuration.
 *
 * Usage: php tools/codelists/update.php [path/to/EN16931-CII-validation.xsl]
 *
 * Run tools/schemas/download.sh first if no path is given.
 */

use Quittio\Core\Codes\CodeListGenerator;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$xsl = $argv[1] ?? $root.'/tools/validators/xrechnung/resources/cii/16b/xsl/EN16931-CII-validation.xsl';

try {
    foreach ((new CodeListGenerator)->generate($xsl) as $name => $count) {
        printf("%-30s %5d codes\n", $name, $count);
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage()."\nRun tools/schemas/download.sh first.\n");
    exit(1);
}
