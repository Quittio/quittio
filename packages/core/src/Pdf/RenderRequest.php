<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf;

/**
 * Input for a PDF renderer.
 */
final readonly class RenderRequest
{
    public function __construct(
        public string $html,
        public string $title,
        public ?string $author = null,
        public ?string $subject = null,
        public string $paper = 'A4',
        public bool $pdfa = true,
        public ?EmbeddedXml $embedded = null,
        public ?string $font = null,
    ) {}
}
