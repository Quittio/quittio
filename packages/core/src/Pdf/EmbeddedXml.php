<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf;

/**
 * Invoice XML to be embedded into a PDF/A-3 (ZUGFeRD/Factur-X, XRechnung in PDF).
 */
final readonly class EmbeddedXml
{
    public function __construct(
        public string $content,
        public string $filename = 'factur-x.xml',
        public string $relationship = 'Alternative',
        public ?string $conformanceLevel = 'EN 16931',
        public string $description = 'Factur-X/ZUGFeRD Invoice',
        public string $documentType = 'INVOICE',
        public string $version = '1.0',
    ) {}
}
