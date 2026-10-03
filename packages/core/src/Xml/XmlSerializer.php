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
    public function syntax(): Syntax;

    public function serialize(InvoiceData $invoice, Profile $profile, SerializationOptions $options = new SerializationOptions): string;
}
