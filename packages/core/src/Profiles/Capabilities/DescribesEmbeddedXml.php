<?php

declare(strict_types=1);

namespace Quittio\Core\Profiles\Capabilities;

/**
 * For profiles whose XML is embedded in a PDF/A-3 with other XMP metadata than Factur-X
 * ("Factur-X/ZUGFeRD Invoice", fx:Version "1.0"), e.g. XRechnung in ZUGFeRD.
 */
interface DescribesEmbeddedXml
{
    /**
     * Description of the embedded file, e.g. "XRechnung Invoice".
     */
    public function xmpDescription(): string;

    /**
     * fx:Version of the XMP metadata, e.g. "3.0" for XRechnung 3.0.
     */
    public function xmpVersion(): string;
}
