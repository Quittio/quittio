<?php

declare(strict_types=1);

namespace Quittio\Core\Xml\Ubl;

use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Xml\SerializationOptions;
use Quittio\Core\Xml\Ubl\Internal\UblDocumentWriter;
use Quittio\Core\Xml\XmlSerializer;

/**
 * Writes OASIS UBL 2.1 Invoice and CreditNote documents according to the EN 16931
 * binding (CEN/TS 16931-3-2). Credit note type codes produce a CreditNote document.
 */
final class UblWriter implements XmlSerializer
{
    public const string NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    public const string NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

    public const string NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public const string NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /**
     * Always Syntax::Ubl.
     */
    public function syntax(): Syntax
    {
        return Syntax::Ubl;
    }

    /**
     * Writes the invoice as a UBL 2.1 document for $profile: a CreditNote for credit note type
     * codes, otherwise an Invoice. Throws CalculationException if the invoice is not calculated and
     * InvalidArgumentException if the profile cannot be written as UBL.
     */
    public function serialize(InvoiceData $invoice, Profile $profile, SerializationOptions $options = new SerializationOptions): string
    {
        if (! $invoice->isCalculated()) {
            throw new CalculationException('The invoice must be calculated before it can be serialised.');
        }
        if (! in_array(Syntax::Ubl, $profile->syntaxes(), true)) {
            throw new InvalidArgumentException(sprintf('The profile %s cannot be written as UBL.', $profile->name()));
        }

        return (new UblDocumentWriter($invoice, $profile, $options))->write();
    }
}
