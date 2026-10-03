<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use Illuminate\Console\Command;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Validation\ValidationResult;

/**
 * Typed access to arguments and options, shared output helpers.
 */
abstract class QuittioCommand extends Command
{
    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function flag(string $name): bool
    {
        return $this->option($name) === true;
    }

    protected function printValidation(ValidationResult $result): void
    {
        if ($result->messages === []) {
            $this->components->info('No findings.');

            return;
        }

        $this->table(
            ['Severity', 'Level', 'Code', 'BT', 'Message'],
            array_map(static fn (ValidationMessage $m): array => [
                $m->severity->value,
                $m->level->value,
                $m->code,
                $m->bt ?? '',
                $m->message.($m->hint === null ? '' : "\n→ ".$m->hint),
            ], $result->messages),
        );
    }

    /**
     * Reads a file path or fails with a readable message.
     */
    protected function readFile(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error(sprintf('The file "%s" does not exist or is not readable.', $path));

            return null;
        }

        return (string) file_get_contents($path);
    }
}
