<?php

declare(strict_types=1);

namespace Quittio\Core\Reading;

/**
 * Extracts the embedded invoice XML from a hybrid PDF (ZUGFeRD/Factur-X, XRechnung in PDF).
 */
interface EmbeddedXmlExtractor
{
    /**
     * @return array{0: string, 1: string}|null file name and XML content, or null if none is embedded
     */
    public function extract(string $pdf): ?array;
}
