<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\Concerns\HasLabel;

/**
 * MIME code of an attached document (BT-125-1).
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
