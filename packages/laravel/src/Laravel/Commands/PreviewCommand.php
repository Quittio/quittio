<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use InvalidArgumentException;
use Quittio\Laravel\Support\Preview;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'quittio:preview')]
final class PreviewCommand extends QuittioCommand
{
    protected $signature = 'quittio:preview
        {--scenario=simple : Sample scenario (simple, many-lines, long-texts, mixed-vat, reverse-charge, ...)}
        {--template= : Blade view of the template}
        {--format=pdf : pdf or html}
        {--locale= : Locale of the labels}
        {--output= : Target file; default storage/app/quittio/preview}';

    protected $description = 'Render a sample invoice with the configured template';

    public function handle(Preview $preview): int
    {
        $scenario = $this->stringOption('scenario') ?? 'simple';
        $format = $this->stringOption('format') ?? 'pdf';

        try {
            $result = $preview->render($scenario, $format, $this->stringOption('template'), $this->stringOption('locale'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $target = $this->stringOption('output') ?? $this->laravel->storagePath('app/quittio/preview/'.$scenario.'.'.$format);
        try {
            $target = $result->core()->save($target);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Preview written to %s', $target));

        return self::SUCCESS;
    }
}
