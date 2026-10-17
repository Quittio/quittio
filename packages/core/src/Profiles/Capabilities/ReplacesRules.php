<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles\Capabilities;

use Quittio\Core\Profiles\Syntax;

/**
 * Profiles that replace built-in rules with own rules under other codes, as the official
 * validator configuration does, e.g. the XRechnung extension: BR-CO-16 by BR-DEX-09. The PHP
 * rules driver does not run the replaced rules for this profile, so a violation is reported once.
 */
interface ReplacesRules
{
    /**
     * Codes of the built-in, profile and country pack rules that are not run.
     *
     * @param  Syntax|null  $syntax  the syntax that will be written, null if not known
     * @return list<string>
     */
    public function replacedRules(?Syntax $syntax): array;
}
