<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

/**
 * What a fee of a payment reminder is.
 */
enum DunningFeeKind: string
{
    /** Costs of the reminder or other fees of the creditor. */
    case Fee = 'fee';

    /**
     * The flat rate for recovery costs of a late payment between businesses (Article 6
     * Directive 2011/7/EU; in Germany 40 EUR, section 288 (5) BGB), once per claim.
     */
    case FlatRate = 'flat_rate';
}
