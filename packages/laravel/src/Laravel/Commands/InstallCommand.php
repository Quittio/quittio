<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use Illuminate\Support\ServiceProvider;
use Quittio\Laravel\Contracts\ManagedInstaller;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'quittio:install')]
final class InstallCommand extends QuittioCommand
{
    protected $signature = 'quittio:install
        {--views : Also publish the Blade templates}
        {--fonts : Also publish the fonts}
        {--managed : Also install the Managed layer (requires quittio/managed)}
        {--force : Overwrite existing files}';

    protected $description = 'Publish the Quittio configuration (with that of the country packs) and optionally templates and fonts';

    public function handle(): int
    {
        $managed = $this->flag('managed');
        if ($managed && ! $this->laravel->bound(ManagedInstaller::class)) {
            $this->components->error('The Managed layer needs the package quittio/managed: composer require quittio/managed');

            return self::FAILURE;
        }

        // The configuration of the installed country packs (quittio.countries.{code}) comes with it,
        // e.g. quittio-de-config: config/quittio/countries/de.php.
        $tags = ['quittio-config', ...self::countryConfigTags()];
        if ($this->flag('views')) {
            $tags[] = 'quittio-views';
        }
        if ($this->flag('fonts')) {
            $tags[] = 'quittio-fonts';
        }

        foreach ($tags as $tag) {
            $this->call('vendor:publish', ['--tag' => $tag, '--force' => $this->flag('force')]);
        }

        if ($managed) {
            $installer = $this->laravel->make(ManagedInstaller::class);
            if ($installer instanceof ManagedInstaller && $installer->install($this, $this->flag('force')) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->components->info('Quittio is installed.');
        $this->components->bulletList([
            'Set the seller in config/quittio.php or via QUITTIO_SELLER_* environment variables.',
            'Install a country pack, e.g. composer require quittio/country-de.',
            'Preview the template: php artisan quittio:preview --scenario=simple',
        ]);

        return self::SUCCESS;
    }

    /**
     * The publish tags quittio-{code}-config of the country packs, e.g. quittio-de-config.
     *
     * @return list<string>
     */
    private static function countryConfigTags(): array
    {
        $tags = [];
        foreach (ServiceProvider::publishableGroups() as $tag) {
            if (is_string($tag) && preg_match('/^quittio-[a-z]{2}-config$/', $tag) === 1) {
                $tags[] = $tag;
            }
        }
        sort($tags);

        return $tags;
    }
}
