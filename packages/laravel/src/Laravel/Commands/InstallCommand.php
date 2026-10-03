<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'quittio:install')]
final class InstallCommand extends QuittioCommand
{
    protected $signature = 'quittio:install
        {--views : Also publish the Blade templates}
        {--fonts : Also publish the fonts}
        {--managed : Install the Managed layer (not available in this release)}
        {--force : Overwrite existing files}';

    protected $description = 'Publish the Quittio configuration and optionally templates and fonts';

    public function handle(): int
    {
        if ($this->flag('managed')) {
            $this->components->warn('The Managed layer (numbering, lifecycle, payments) is not part of this release. Installing the core integration only.');
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
