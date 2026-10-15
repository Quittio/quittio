<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use Illuminate\Filesystem\Filesystem;
use Quittio\Laravel\QuittioServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'make:quittio-template')]
final class MakeTemplateCommand extends QuittioCommand
{
    protected $signature = 'make:quittio-template {name : Name of the template, e.g. my-design} {--force : Overwrite an existing template}';

    protected $description = 'Create a new invoice template from the default template';

    public function handle(Filesystem $files): int
    {
        $name = (string) $this->stringArgument('name');
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) !== 1) {
            $this->components->error('Use lower case letters, digits, "-" and "_" for the template name.');

            return self::FAILURE;
        }

        $target = $this->laravel->resourcePath('views/invoices/'.$name);
        if ($files->isDirectory($target) && ! $this->flag('force')) {
            $this->components->error(sprintf('The template %s already exists. Use --force to overwrite it.', $target));

            return self::FAILURE;
        }

        $source = QuittioServiceProvider::packagePath('resources/views/templates/default');
        $files->copyDirectory($source, $target);

        $layout = $target.'/layout.blade.php';
        $files->put($layout, str_replace(
            "'quittio::templates.default.partials'",
            "'invoices.".$name.".partials'",
            $files->get($layout),
        ));

        $this->components->info(sprintf('Template created in %s', $target));
        $this->line(sprintf("Use it per call with ->template('invoices.%s.layout') (allowed by quittio.pdf.templates) or globally via quittio.pdf.template.", $name));

        return self::SUCCESS;
    }
}
