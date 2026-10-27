<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\Concerns\HasLabel;

/**
 * Invoice note subject code (BT-21), UNTDID 4451 subset. Further codes can be used as RawCode.
 */
enum NoteSubjectCode: string
{
    use HasLabel;

    case GeneralInformation = 'AAI';
    case PriceConditions = 'AAK';
    case LegalInformation = 'ABL';
    case Introduction = 'ACY';
    case PaymentDetail = 'PMD';
    case PaymentInformation = 'PMT';
    case RegulatoryInformation = 'REG';
    case SupplierRemarks = 'SUR';
    case TaxDeclaration = 'TXD';

    /**
     * The labels of these codes are under "note_subject" in resources/lang/{locale}/codes.php (see
     * label()).
     */
    public static function labelGroup(): string
    {
        return 'note_subject';
    }

    /**
     * Whether a raw code value is valid according to the official code list.
     */
    public static function isValidCode(string $code): bool
    {
        return CodeList::contains(CodeList::NOTE_SUBJECTS, $code);
    }
}
