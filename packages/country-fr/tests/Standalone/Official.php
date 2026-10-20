<?php

declare(strict_types=1);

namespace Quittio\Core\Tests\Support;

/**
 * Stand-in for the helper of the core tests in the split repository quittio/country-fr, where
 * neither the core tests nor the validators of tools/schemas/download.sh exist: the tests that
 * need the official validators are skipped there.
 */
final class Official
{
    public static function available(): bool
    {
        return false;
    }
}
