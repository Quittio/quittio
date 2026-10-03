<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Enums\Concerns\HasLabel;

/**
 * Document type code for additional supporting documents (BG-24), UNTDID 1001 subset.
 */
enum DocumentReferenceTypeCode: string
{
    use HasLabel;

    case CallForTender = '50';
    case InvoicedObject = '130';
    case AdditionalDocument = '916';

    public static function labelGroup(): string
    {
        return 'document_reference';
    }
}
