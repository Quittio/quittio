<?php

declare(strict_types=1);

namespace Quittio\Core\Xml;

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Profiles\Syntax;

/**
 * Writes calculated invoice data as XML in one syntax.
 */
interface XmlSerializer
{
    /**
     * The XML syntax this serializer writes, e.g. CII or UBL.
     */
    public function syntax(): Syntax;

    /**
     * Writes the calculated invoice as XML of this syntax for $profile; throws CalculationException
     * if the invoice is not calculated.
     */
    public function serialize(InvoiceData $invoice, Profile $profile, SerializationOptions $options = new SerializationOptions): string;
}
