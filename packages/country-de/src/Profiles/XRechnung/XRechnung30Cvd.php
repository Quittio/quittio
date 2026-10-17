<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Profiles\XRechnung;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Profiles\Capabilities\ExtendsCodeLists;
use Quittio\Core\Standards\StandardVersion;

/**
 * XRechnung CVD 0.9: XRechnung 3.0 for the procurement of road vehicles under the Clean Vehicles
 * Directive (EU) 2019/1161. It requires the contract (BT-12) and tender or lot reference (BT-17)
 * and at least one line that states the vehicle category as item classification (BT-158, scheme
 * CVD) and the vehicle type as item attribute "cva" (BT-160, BT-161), see BR-DE-CVD-01 to 06-b.
 * Choose it explicitly with ->profile('xrechnung-cvd'); the automatic format choice never does.
 */
final class XRechnung30Cvd extends XRechnung30 implements ExtendsCodeLists
{
    public const string SPECIFICATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0#compliant#urn:xeinkauf.de:kosit:xrechnung:cvd_0.9';

    /** Scheme identifier (BT-158-1) of the vehicle category. */
    public const string CLASSIFICATION_SCHEME = 'CVD';

    /** Vehicle categories M1 to N3 (BR-DE-CVD-04). */
    public const array VEHICLE_CATEGORIES = ['M1', 'M2', 'M3', 'N1', 'N2', 'N3'];

    /** Name (BT-160) of the item attribute with the vehicle type. */
    public const string ATTRIBUTE = 'cva';

    /** Values (BT-161) of the item attribute cva (BR-DE-CVD-05). */
    public const array ATTRIBUTE_VALUES = ['clean', 'zero-emission', 'other'];

    public function key(): string
    {
        return 'xrechnung-cvd';
    }

    public function name(): string
    {
        return 'XRechnung 3.0 CVD';
    }

    public function specificationId(StandardVersion $version = StandardVersion::En16931_2017): string
    {
        return self::SPECIFICATION_ID;
    }

    /**
     * The scheme CVD of the item classification (BR-TMP-CVD-01: UNTDID 7143 plus CVD).
     */
    public function additionalCodes(string $codeList): array
    {
        return $codeList === CodeList::ITEM_CLASSIFICATIONS ? [self::CLASSIFICATION_SCHEME] : [];
    }
}
