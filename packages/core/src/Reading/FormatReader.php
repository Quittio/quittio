<?php

declare(strict_types=1);

namespace Quittio\Core\Reading;

use DOMDocument;

/**
 * Reads one XML invoice format into the data model.
 */
interface FormatReader
{
    public function canRead(DOMDocument $document): bool;

    public function read(DOMDocument $document, string $xml): ReadResult;
}
