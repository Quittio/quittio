<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Brick\Money\Money;

/**
 * Fee of a payment reminder (e.g. costs of the reminder, flat rate according to national law).
 *
 * The amount is charged without VAT: dunning costs and the flat rate of section 288 (5)
 * BGB are damages, not consideration for a supply (UStAE 1.3 Abs. 6). Only actual costs
 * may be claimed as reminder fees.
 */
final readonly class DunningFee
{
    public function __construct(
        public string $description,
        public Money $amount,
    ) {}
}
