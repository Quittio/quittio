<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Profiles\XRechnung;

use Quittio\Core\Profiles\AbstractProfile;
use Quittio\Core\Profiles\Capabilities\DescribesEmbeddedXml;
use Quittio\Core\Profiles\Capabilities\RequiresVatBreakdownRate;
use Quittio\Core\Profiles\Capabilities\StructuredCashDiscountText;
use Quittio\Core\Profiles\PeppolBis3;
use Quittio\Core\Profiles\ProfileLevel;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Standards\StandardVersion;
use Quittio\Country\DE\Rules\XRechnungRules;

/**
 * XRechnung 3.0 (German CIUS of EN 16931), syntax CII or UBL.
 *
 * Embedded in a ZUGFeRD PDF it uses the file name xrechnung.xml and the conformance level XRECHNUNG.
 */
class XRechnung30 extends AbstractProfile implements DescribesEmbeddedXml, RequiresVatBreakdownRate, StructuredCashDiscountText
{
    public const string SPECIFICATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';

    public function key(): string
    {
        return 'xrechnung';
    }

    public function name(): string
    {
        return 'XRechnung 3.0';
    }

    public function level(): ProfileLevel
    {
        return ProfileLevel::En16931;
    }

    public function specificationId(StandardVersion $version = StandardVersion::En16931_2017): string
    {
        return self::SPECIFICATION_ID;
    }

    public function syntaxes(): array
    {
        return [Syntax::Cii, Syntax::Ubl];
    }

    public function embeddedFilename(): ?string
    {
        return 'xrechnung.xml';
    }

    public function xmpConformanceLevel(): ?string
    {
        return 'XRECHNUNG';
    }

    public function xmpDescription(): string
    {
        return 'XRechnung Invoice';
    }

    /**
     * The XRechnung version as fx:Version, as ZUGFeRD 2.x prescribes for the level XRECHNUNG.
     */
    public function xmpVersion(): string
    {
        return '3.0';
    }

    public function requiredBusinessTerms(): array
    {
        return ['BT-10', 'BT-34', 'BT-49', 'BG-6', 'BG-16'];
    }

    public function defaultBusinessProcess(): ?string
    {
        return PeppolBis3::BUSINESS_PROCESS;
    }

    public function rules(): array
    {
        return XRechnungRules::all();
    }
}
