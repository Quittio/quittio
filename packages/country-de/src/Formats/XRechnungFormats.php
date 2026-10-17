<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Formats;

use Quittio\Core\Formats\OutputFormat;
use Quittio\Core\Profiles\Syntax;

/**
 * The XRechnung formats of the German pack: XRechnung in CII (default) and in UBL, each with the
 * profiles XRechnung, XRechnung extension and XRechnung CVD.
 */
final class XRechnungFormats
{
    public const string CII = 'xrechnung-cii';

    public const string UBL = 'xrechnung-ubl';

    /** Alias of the default syntax. */
    public const string ALIAS = 'xrechnung';

    /**
     * @return list<OutputFormat>
     */
    public static function all(): array
    {
        return [
            new OutputFormat(self::CII, Syntax::Cii, defaultProfile: 'xrechnung', profiles: ['xrechnung', 'xrechnung-extension', 'xrechnung-cvd']),
            new OutputFormat(self::UBL, Syntax::Ubl, defaultProfile: 'xrechnung', profiles: ['xrechnung', 'xrechnung-extension', 'xrechnung-cvd']),
        ];
    }
}
