<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use Quittio\Laravel\QuittioManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'quittio:validate')]
final class ValidateCommand extends QuittioCommand
{
    protected $signature = 'quittio:validate
        {file : XML or PDF file}
        {--profile= : Expected profile}
        {--kosit : Also run the KoSIT validator (configured in quittio.validation.drivers.kosit)}
        {--verapdf : Also run veraPDF (configured in quittio.validation.drivers.verapdf)}
        {--json : Print the result as JSON}';

    protected $description = 'Validate an e-invoice file and print a report';

    public function handle(QuittioManager $quittio): int
    {
        $path = (string) $this->stringArgument('file');
        $content = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($content === false) {
            return $this->cannotValidate(sprintf('The file "%s" does not exist or is not readable.', $path));
        }

        $levels = array_values(array_filter(['kosit', 'verapdf'], $this->flag(...)));
        $expected = $this->stringOption('profile');

        try {
            // The drivers get all their settings of validation.drivers.*, as in a render run;
            // the cached core of the application is not changed.
            $core = $levels === [] ? $quittio->core() : $quittio->coreWithLevels($levels);
            $expectedProfile = $expected === null ? null : $core->profiles()->get($expected)->key();
            $result = $core->validateFile($content);
            $read = $core->read($content);
        } catch (Throwable $e) {
            return $this->cannotValidate($e->getMessage());
        }

        $profileMismatch = $expectedProfile !== null && $expectedProfile !== $read->profile;

        if ($this->flag('json')) {
            $this->json([
                ...$result->toArray(),
                'profile' => $read->profile,
                'expectedProfile' => $expectedProfile,
                'profileMismatch' => $profileMismatch,
            ]);
        } else {
            $this->components->twoColumnDetail('Invoice', $read->data->number);
            $this->components->twoColumnDetail('Syntax', strtoupper($read->syntax));
            $this->components->twoColumnDetail('Profile', $read->profile ?? ($read->specificationId ?? 'unknown'));
            $this->components->twoColumnDetail('Levels', implode(', ', array_map(static fn ($l): string => $l->value, $result->levels)));
            $this->printValidation($result);
            if ($profileMismatch) {
                $this->components->error(sprintf('Expected profile "%s", found "%s".', $expectedProfile, $read->profile ?? 'unknown'));
            }
            $result->isValid() ? $this->components->info('The file is valid.') : $this->components->error('The file is not valid.');
        }

        return $result->isValid() && ! $profileMismatch ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The file could not be checked at all (missing, not an e-invoice, unknown profile): exit code 2,
     * so that scripts can tell it from an invalid file (1).
     */
    private function cannotValidate(string $message): int
    {
        if ($this->flag('json')) {
            $this->json(['valid' => false, 'error' => $message]);
        } else {
            $this->components->error($message);
        }

        return self::INVALID;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): void
    {
        $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
