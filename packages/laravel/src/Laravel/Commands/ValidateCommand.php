<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use Quittio\Laravel\QuittioManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

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
        $content = $this->readFile((string) $this->stringArgument('file'));
        if ($content === null) {
            return self::FAILURE;
        }

        $levels = array_values(array_filter(['kosit', 'verapdf'], $this->flag(...)));

        try {
            // The drivers get all their settings of validation.drivers.*, as in a render run;
            // the cached core of the application is not changed.
            $core = $levels === [] ? $quittio->core() : $quittio->coreWithLevels($levels);
            $result = $core->validateFile($content);
            $read = $core->read($content);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $expected = $this->stringOption('profile');
        $profileMismatch = $expected !== null && $core->profiles()->get($expected)->key() !== $read->profile;

        if ($this->flag('json')) {
            $this->line((string) json_encode([...$result->toArray(), 'profile' => $read->profile], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Invoice', $read->data->number);
            $this->components->twoColumnDetail('Syntax', strtoupper($read->syntax));
            $this->components->twoColumnDetail('Profile', $read->profile ?? ($read->specificationId ?? 'unknown'));
            $this->components->twoColumnDetail('Levels', implode(', ', array_map(static fn ($l): string => $l->value, $result->levels)));
            $this->printValidation($result);
            if ($profileMismatch) {
                $this->components->error(sprintf('Expected profile "%s", found "%s".', $expected, $read->profile ?? 'unknown'));
            }
            $result->isValid() ? $this->components->info('The file is valid.') : $this->components->error('The file is not valid.');
        }

        return $result->isValid() && ! $profileMismatch ? self::SUCCESS : self::FAILURE;
    }
}
