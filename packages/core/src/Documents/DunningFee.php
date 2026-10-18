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
    /**
     * @param  string|null  $claim  the claim of a flat rate, e.g. the invoice or instalment number
     */
    public function __construct(
        public string $description,
        public Money $amount,
        public DunningFeeKind $kind = DunningFeeKind::Fee,
        public ?string $claim = null,
    ) {}
}
