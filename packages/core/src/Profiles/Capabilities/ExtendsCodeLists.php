<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles\Capabilities;

/**
 * Profiles that accept additional codes on top of the EN 16931 code lists,
 * e.g. the identifier schemes XR01 to XR03 of the XRechnung extension.
 */
interface ExtendsCodeLists
{
    /**
     * @return list<string>
     */
    public function additionalCodes(string $codeList): array;
}
