<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use Quittio\Core\Profiles\Profile;
use Quittio\Laravel\QuittioManager;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'quittio:countries')]
final class CountriesCommand extends QuittioCommand
{
    protected $signature = 'quittio:countries';

    protected $description = 'Show the installed country packs and their rules';

    public function handle(QuittioManager $quittio): int
    {
        $packs = $quittio->countries()->all();
        if ($packs === []) {
            $this->components->warn('No country pack is installed. Only EN 16931 rules are checked. Example: composer require quittio/country-de');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($packs as $pack) {
            $defaults = $pack->defaults();
            $rows[] = [
                $pack->code(),
                $pack::class,
                implode(', ', array_map(static fn (Profile $p): string => $p->key(), $pack->profiles())),
                (string) count($pack->rules()),
                (string) count($pack->formatRules()),
                sprintf('%s · %s · %s', $defaults->currency, $defaults->locale, $defaults->profile),
            ];
        }

        $this->table(['Code', 'Class', 'Profiles', 'Rules', 'Format rules', 'Defaults'], $rows);

        return self::SUCCESS;
    }
}
