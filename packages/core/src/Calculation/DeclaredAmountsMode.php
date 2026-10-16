<?php

declare(strict_types=1);

namespace Quittio\Core\Calculation;

/**
 * What the calculator does with the declared amounts of an existing document (line net amounts
 * BT-131 and VAT breakdown BT-116, BT-117 that another system rounded differently, see
 * Meta::FLAG_DECLARED_AMOUNTS).
 */
enum DeclaredAmountsMode: string
{
    /**
     * Keeps declared amounts within the tolerances of EN 16931 and Peppol, so that foreign
     * rounding is reversed exactly; amounts outside the tolerances are recalculated and reported
     * (QUITTIO-DECLARED-AMOUNTS).
     */
    case Keep = 'keep';

    /**
     * Recalculates every amount and reports that declared amounts were replaced
     * (QUITTIO-DECLARED-AMOUNTS). Partial cancellations aligned with the earlier ones keep their
     * VAT, otherwise cumulative rounding would no longer reverse the original.
     */
    case Recalculate = 'recalculate';

    /**
     * Like Keep, but declared amounts outside the tolerances throw a CalculationException
     * instead of being recalculated.
     */
    case Reject = 'reject';
}
