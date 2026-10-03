<?php

declare(strict_types=1);

namespace Quittio\Core\Xml;

/**
 * Options for XML serialisation.
 */
final readonly class SerializationOptions
{
    public function __construct(
        public string $locale = 'en',
        public bool $indent = true,
    ) {}
}
