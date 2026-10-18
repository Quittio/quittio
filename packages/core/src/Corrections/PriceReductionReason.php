<?php

declare(strict_types=1);

namespace Quittio\Core\Corrections;

/**
 * Why the consideration of an invoice was reduced (PriceReduction); the note names it.
 */
enum PriceReductionReason: string
{
    /** A cash discount (Skonto) the buyer took when paying. */
    case CashDiscount = 'cash_discount';

    /** A reduction because of a complaint about defects that does not change the supply. */
    case Defect = 'defect';

    /** A bonus, e.g. a turnover bonus for the invoice. */
    case Bonus = 'bonus';

    /** Any other reduction of the consideration. */
    case Other = 'other';
}
