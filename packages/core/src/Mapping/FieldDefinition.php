<?php

declare(strict_types=1);

namespace Quittio\Core\Mapping;

use Quittio\Core\Profiles\ProfileLevel;

/**
 * Metadata of one DTO field, read from its mapping attributes.
 */
final readonly class FieldDefinition
{
    /**
     * @param  class-string  $class
     * @param  list<string>  $requiredIn
     */
    public function __construct(
        public string $class,
        public string $property,
        public ?string $bt,
        public ?string $cardinality,
        public ProfileLevel $level,
        public array $requiredIn,
        public bool $extendedOnly,
        public bool $xrechnungExtensionOnly,
        public bool $computed,
        public bool $pdfOnly,
        public bool $internal,
        public ?string $cii,
        public ?string $ubl,
    ) {}

    /**
     * Whether the field must be present: cardinality 1 or 1..n. Fields without a cardinality are
     * optional.
     */
    public function isMandatory(): bool
    {
        return $this->cardinality === '1' || $this->cardinality === '1..n';
    }
}
