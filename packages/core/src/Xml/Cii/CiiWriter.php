<?php

declare(strict_types=1);

namespace Quittio\Core\Xml\Cii;

use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Xml\Cii\Internal\CiiDocumentWriter;
use Quittio\Core\Xml\SerializationOptions;
use Quittio\Core\Xml\XmlSerializer;

/**
 * Writes UN/CEFACT Cross Industry Invoice (D16B) according to the EN 16931 binding
 * (CEN/TS 16931-3-3) and the Factur-X/ZUGFeRD profiles.
 *
 * The element order follows the XSD sequences. Fields that the profile does not
 * contain are omitted (the field matrix validation reports them as warnings).
 */
final class CiiWriter implements XmlSerializer
{
    public const string NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';

    public const string NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

    public const string NS_QDT = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';

    public const string NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

    /**
     * Always Syntax::Cii.
     */
    public function syntax(): Syntax
    {
        return Syntax::Cii;
    }

    /**
     * Writes the invoice as a CII D16B document for $profile; fields the profile does not contain
     * are left out. Throws CalculationException if the invoice is not calculated.
     */
    public function serialize(InvoiceData $invoice, Profile $profile, SerializationOptions $options = new SerializationOptions): string
    {
        if (! $invoice->isCalculated()) {
            throw new CalculationException('The invoice must be calculated before it can be serialised.');
        }

        return (new CiiDocumentWriter($invoice, $profile, $options))->write();
    }
}
