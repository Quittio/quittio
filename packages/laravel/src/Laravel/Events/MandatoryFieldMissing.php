<?php

declare(strict_types=1);

namespace Quittio\Laravel\Events;

use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Data\InvoiceData;

/**
 * Dispatched when a template omitted mandatory values (specification 11.8).
 */
final readonly class MandatoryFieldMissing
{
    /**
     * @param  list<MandatoryField>  $fields
     */
    public function __construct(
        public InvoiceData $invoice,
        public array $fields,
    ) {}
}
