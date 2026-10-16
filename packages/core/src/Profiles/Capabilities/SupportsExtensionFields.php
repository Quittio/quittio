<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles\Capabilities;

/**
 * Profiles that transmit the fields of extensions of EN 16931, e.g. "xrechnung" for the fields
 * marked with Mapping\Attributes\XRechnungExtensionOnly.
 */
interface SupportsExtensionFields
{
    /**
     * Names of the supported extensions, e.g. ['xrechnung'].
     *
     * @return list<string>
     */
    public function extensions(): array;
}
