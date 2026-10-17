<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Profiles\XRechnung;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\MimeType;
use Quittio\Core\Profiles\Capabilities\ExtendsCodeLists;
use Quittio\Core\Profiles\Capabilities\ReplacesRules;
use Quittio\Core\Profiles\Capabilities\SupportsExtensionFields;
use Quittio\Core\Profiles\Capabilities\SupportsSubLines;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Standards\StandardVersion;

/**
 * XRechnung 3.0 extension: adds sub invoice lines (UBL only) and third party payments.
 * Choose it explicitly with ->profile('xrechnung-extension'): neither the automatic format
 * choice nor the profile xrechnung switch to it when extension data is used; with xrechnung
 * such data is reported as an error.
 */
final class XRechnung30Extension extends XRechnung30 implements ExtendsCodeLists, ReplacesRules, SupportsExtensionFields, SupportsSubLines
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
     * The extension accepts the identifier schemes XR01 to XR03, checked by BR-DEX-04 to
     * BR-DEX-08 (XRechnungRules), which replace the code list rules (see replacedRules()), and
     * attachments with the MIME code application/xml (BR-DEX-01 instead of BR-CL-24).
     */
    public function additionalCodes(string $codeList): array
    {
        return match ($codeList) {
            CodeList::ICD, CodeList::EAS => ['XR01', 'XR02', 'XR03'],
            CodeList::MIME_TYPES => [MimeType::Xml->value],
            default => [],
        };
    }

    /**
     * As in the official validator configuration, where the replaced rules are only an
     * information: BR-DEX-04 to BR-DEX-08 replace BR-CL-10, -11, -21, -25 and -26 (identifier
     * schemes with XR01 to XR03); in UBL BR-DEX-09 replaces BR-CO-16 (amount due with third
     * party payments) and BR-DEX-02 replaces QUITTIO-SUBLINES (sum of the sub lines).
     */
    public function replacedRules(?Syntax $syntax): array
    {
        $replaced = ['BR-CL-10', 'BR-CL-11', 'BR-CL-21', 'BR-CL-25', 'BR-CL-26'];

        return $syntax === Syntax::Cii ? $replaced : [...$replaced, 'BR-CO-16', 'QUITTIO-SUBLINES'];
    }

    /**
     * The fields of the XRechnung extension (Mapping\Attributes\XRechnungExtensionOnly).
     */
    public function extensions(): array
    {
        return ['xrechnung'];
    }
}
