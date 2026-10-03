<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use Quittio\Core\Validation\Drivers\KositArtifacts;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'quittio:schemas:download')]
final class SchemasDownloadCommand extends QuittioCommand
{
    protected $signature = 'quittio:schemas:download
        {--path= : Target directory, default storage/quittio/validators}
        {--force : Download again}
        {--without-peppol : Skip compiling the Peppol BIS Billing 3.0 rules (needs Java)}
        {--java=java : Java binary for compiling the Peppol rules}';

    protected $description = 'Download the KoSIT validator and the XRechnung validator configuration';

    public function handle(KositArtifacts $artifacts): int
    {
        $target = $this->stringOption('path') ?? $this->laravel->storagePath('quittio/validators');

        try {
            $paths = $artifacts->download($target, $this->flag('force'));
            $peppol = $this->flag('without-peppol') ? null : $artifacts->compilePeppol($target, $this->stringOption('java') ?? 'java', $this->flag('force'));
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Validator artifacts are ready.');
        $this->line('Add to config/quittio.php (validation.drivers.kosit) for the cli mode:');
        $this->line(sprintf("    'mode' => 'cli',\n    'jar' => '%s',\n    'scenarios' => '%s',\n    'repository' => '%s',", $paths['jar'], $paths['scenarios'], $paths['repository']));
        if ($peppol !== null) {
            $this->line(sprintf("    'configurations' => [['scenarios' => '%s', 'repository' => '%s']],", $peppol['scenarios'], $peppol['repository']));
        }
        $this->line("and add 'kosit' to validation.levels. Java 11 or newer is required.");

        return self::SUCCESS;
    }
}
