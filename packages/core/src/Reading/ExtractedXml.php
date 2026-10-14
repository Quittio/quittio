<?php

declare(strict_types=1);

namespace Quittio\Core\Reading;

use Quittio\Core\Validation\ValidationMessage;

/**
 * The invoice XML found in a hybrid PDF, with the file specification it came from.
 */
final readonly class ExtractedXml
{
    /**
     * @param  string|null  $relationship  /AFRelationship of the file specification (e.g. "Alternative", "Data")
     * @param  list<ValidationMessage>  $findings  observations of the extractor (e.g. several candidates)
     */
    public function __construct(
        public string $filename,
        public string $xml,
        public ?string $relationship = null,
        public array $findings = [],
    ) {}
}
