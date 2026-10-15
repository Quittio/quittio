<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use Quittio\Core\Validation\Drivers\KositArtifacts;
use Quittio\Laravel\QuittioManager;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'quittio:schemas:download')]
final class SchemasDownloadCommand extends QuittioCommand
{
    protected $signature = 'quittio:schemas:download
        {--path= : Target directory, default storage/quittio/validators}
        {--force : Download again}
        {--without-peppol : Skip compiling the Peppol BIS Billing 3.0 rules (needs Java)}
        {--java=java : Java binary for compiling the Peppol rules}';

    protected $description = 'Download the KoSIT validator and the XRechnung validator configuration';

    public function handle(KositArtifacts $artifacts, QuittioManager $quittio): int
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

        // The daemon listens on 8080 by default; started on the port of the configured URL,
        // the default mode (daemon) works without further settings.
        $url = $quittio->settings()->string('validation.drivers.kosit.url', 'http://127.0.0.1:8081');
        $port = parse_url($url, PHP_URL_PORT);
        $configurations = sprintf('-s %s -r %s', escapeshellarg($paths['scenarios']), escapeshellarg($paths['repository']));
        if ($peppol !== null) {
            $configurations .= sprintf(' -s %s -r %s', escapeshellarg($peppol['scenarios']), escapeshellarg($peppol['repository']));
        }
        $this->newLine();
        $this->line(sprintf('For the mode daemon (url %s), start the validator with:', $url));
        $this->line(sprintf('    java -jar %s %s -D%s', escapeshellarg($paths['jar']), $configurations, is_int($port) ? ' -P '.$port : ''));

        return self::SUCCESS;
    }
}
