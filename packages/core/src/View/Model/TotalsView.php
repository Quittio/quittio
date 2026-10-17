<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * The document totals (BG-22).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read ?FormattedValue $lineNetSum Sum of the line net amounts (BT-106)
 * @property-read ?FormattedValue $allowanceSum Sum of the allowances (BT-107)
 * @property-read ?FormattedValue $chargeSum Sum of the charges (BT-108)
 * @property-read ?FormattedValue $netTotal Total without VAT (BT-109)
 * @property-read ?FormattedValue $vatTotal VAT total (BT-110)
 * @property-read ?FormattedValue $vatTotalInTaxCurrency VAT in accounting currency (BT-111)
 * @property-read ?string $taxCurrencyNote Note on the VAT in accounting currency
 * @property-read ?FormattedValue $grossTotal Total with VAT (BT-112)
 * @property-read bool $dueEqualsGross The amount due is the gross total
 * @property-read ?FormattedValue $prepaid Paid amount (BT-113)
 * @property-read ?FormattedValue $rounding Rounding amount (BT-114)
 * @property-read ViewList<DescribedAmountView> $thirdPartyPayments Third party payments (XRechnung extension)
 * @property-read ?FormattedValue $amountDue Amount due (BT-115); refunds as positive amount
 * @property-read string $amountDueLabel "Amount due", "Amount to be refunded" or "Amount paid out"
 */
final class TotalsView extends ViewNode {}
