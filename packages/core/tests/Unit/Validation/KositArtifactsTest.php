<?php

declare(strict_types=1);

use Quittio\Core\Tests\Support\Official;
use Quittio\Core\Validation\Drivers\KositArtifacts;

it('compiles the Peppol Schematron with the Saxon of the validator', function () {
    $target = sys_get_temp_dir().'/quittio-peppol-'.bin2hex(random_bytes(4));
    mkdir($target);
    copy(Official::root().'/validator.jar', $target.'/validator.jar');
    // Local copies of the official files instead of a network download.
    $artifacts = new KositArtifacts(fetch: fn (string $url): string => (string) file_get_contents(
        str_ends_with($url, '.sch') ? Official::root().'/peppol/PEPPOL-EN16931-UBL.sch' : Official::root().'/peppol/schxslt.jar',
    ));

    $result = $artifacts->compilePeppol($target);

    expect($result['scenarios'])->toBe(KositArtifacts::peppolScenarios())
        ->and(file_get_contents($target.'/peppol/PEPPOL-EN16931-UBL.xsl'))->toContain('PEPPOL-EN16931-R001');
})->group('official')->skip(fn () => ! Official::available() || ! is_file(Official::root().'/peppol/schxslt.jar'), 'KoSIT validator or Peppol sources are not available.');

it('requires the validator before compiling', function () {
    (new KositArtifacts(fn (): string => ''))->compilePeppol(sys_get_temp_dir().'/quittio-missing-'.bin2hex(random_bytes(4)));
})->throws(RuntimeException::class, 'validator JAR');
