<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Profiles\XRechnung;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Profiles\Capabilities\ExtendsCodeLists;
use Quittio\Core\Profiles\Capabilities\SupportsExtensionFields;
use Quittio\Core\Profiles\Capabilities\SupportsSubLines;
use Quittio\Core\Standards\StandardVersion;

/**
 * XRechnung 3.0 extension: adds sub invoice lines (UBL only) and third party payments.
 * Choose it explicitly with ->profile('xrechnung-extension'): neither the automatic format
 * choice nor the profile xrechnung switch to it when extension data is used; with xrechnung
 * such data is reported as an error.
 */
final class XRechnung30Extension extends XRechnung30 implements ExtendsCodeLists, SupportsExtensionFields, SupportsSubLines
{
    public const string SPECIFICATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0#conformant#urn:xeinkauf.de:kosit:extension:xrechnung_3.0';

    public function key(): string
    {
        return 'xrechnung-extension';
    }

    public function name(): string
    {
        return 'XRechnung 3.0 Extension';
    }

    public function specificationId(StandardVersion $version = StandardVersion::En16931_2017): string
    {
        return self::SPECIFICATION_ID;
    }

    /**
     * ZUGFeRD 2.x names XRechnung documents, also with the extension, "XRECHNUNG".
     */
    public function xmpConformanceLevel(): string
    {
        return 'XRECHNUNG';
    }

    /**
     * The extension accepts the identifier schemes XR01 to XR03 (BR-DEX-04 to BR-DEX-08).
     */
    public function additionalCodes(string $codeList): array
    {
        return in_array($codeList, [CodeList::ICD, CodeList::EAS], true) ? ['XR01', 'XR02', 'XR03'] : [];
    }

    /**
     * The fields of the XRechnung extension (Mapping\Attributes\XRechnungExtensionOnly).
     */
    public function extensions(): array
    {
        return ['xrechnung'];
    }
}
