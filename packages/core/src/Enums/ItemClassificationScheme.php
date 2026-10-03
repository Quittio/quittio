<?php

declare(strict_types=1);

namespace Quittio\Core\Enums;

use Quittio\Core\Codes\CodeList;
use Quittio\Core\Enums\Concerns\HasLabel;

/**
 * Item classification scheme (BT-158-1), UNTDID 7143 subset. Further codes can be used as RawCode.
 */
enum ItemClassificationScheme: string
{
    use HasLabel;

    case HarmonisedSystem = 'HS';
    case Unspsc = 'TST';
    case CustomsArticleNumber = 'CV';
    case NationalProductGroup = 'GN';
    case Isbn = 'IB';
    case ProductServiceId = 'MP';
    case MutuallyDefined = 'ZZZ';

    public static function labelGroup(): string
    {
        return 'item_classification';
    }

    /**
     * Whether a raw code value is valid according to the official code list.
     */
    public static function isValidCode(string $code): bool
    {
        return CodeList::contains(CodeList::ITEM_CLASSIFICATIONS, $code);
    }
}
