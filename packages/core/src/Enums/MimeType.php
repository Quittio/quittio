<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\Concerns\HasLabel;

/**
 * MIME code of an attached document (BT-125-1). Xml (application/xml) is not part of the
 * EN 16931 code list (BR-CL-24); only the XRechnung extension allows it (BR-DEX-01).
 */
enum MimeType: string
{
    use HasLabel;

    case Pdf = 'application/pdf';
    case Png = 'image/png';
    case Jpeg = 'image/jpeg';
    case Csv = 'text/csv';
    case Xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    case Ods = 'application/vnd.oasis.opendocument.spreadsheet';
    case Xml = 'application/xml';

    public static function labelGroup(): string
    {
        return 'mime';
    }

    /**
     * Whether a raw code value is valid according to the official code list.
     */
    public static function isValidCode(string $code): bool
    {
        return CodeList::contains(CodeList::MIME_TYPES, $code);
    }
}
