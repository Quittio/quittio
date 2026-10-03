<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use Quittio\Core\Codes\CodeListGenerator;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'quittio:codelists:update')]
final class CodeListsUpdateCommand extends QuittioCommand
{
    protected $signature = 'quittio:codelists:update
        {xsl? : EN16931-CII-validation.xsl of the validator configuration}
        {--target= : Output directory, default: the code lists of quittio/core}';

    protected $description = 'Regenerate the code lists from the official EN 16931 Schematron rules (development)';

    public function handle(): int
    {
        $xsl = $this->stringArgument('xsl')
            ?? $this->laravel->storagePath('quittio/validators/xrechnung/resources/cii/16b/xsl/EN16931-CII-validation.xsl');

        try {
            $counts = (new CodeListGenerator)->generate($xsl, $this->stringOption('target'));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage().' Run php artisan quittio:schemas:download first or pass the XSL path.');

            return self::FAILURE;
        }

        $this->table(['Code list', 'Codes'], array_map(static fn (string $name, int $count): array => [$name, (string) $count], array_keys($counts), $counts));

        return self::SUCCESS;
    }
}
