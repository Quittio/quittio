<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use Quittio\Laravel\Contracts\ManagedInstaller;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'quittio:install')]
final class InstallCommand extends QuittioCommand
{
    protected $signature = 'quittio:install
        {--views : Also publish the Blade templates}
        {--fonts : Also publish the fonts}
        {--managed : Also install the Managed layer (requires quittio/managed)}
        {--force : Overwrite existing files}';

    protected $description = 'Publish the Quittio configuration and optionally templates and fonts';

    public function handle(): int
    {
        $managed = $this->flag('managed');
        if ($managed && ! $this->laravel->bound(ManagedInstaller::class)) {
            $this->components->error('The Managed layer needs the package quittio/managed: composer require quittio/managed');

            return self::FAILURE;
        }

        $tags = ['quittio-config'];
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
}
