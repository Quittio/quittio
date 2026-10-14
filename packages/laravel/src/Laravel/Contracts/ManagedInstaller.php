<?php

declare(strict_types=1);

namespace Quittio\Laravel\Contracts;

use Illuminate\Console\Command;

/**
 * Implemented by quittio/managed: runs "quittio:install --managed" (migrations, first issuer).
 */
interface ManagedInstaller
{
    /**
     * @return int command exit code
     */
    public function install(Command $command, bool $force): int;
}
