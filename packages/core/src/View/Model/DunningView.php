<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * A payment reminder.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read int $level Dunning level
 * @property-read ?FormattedValue $deadline Payment deadline
 * @property-read ?string $deadlineText Ready text, e.g. "Please pay the total amount by …"
 * @property-read ViewList<OpenItemView> $openItems Open invoices
 * @property-read ViewList<DescribedAmountView> $fees Reminder fees
 * @property-read ViewList<InterestView> $interest Interest charges
 * @property-read ?FormattedValue $openTotal Sum of the open amounts
 * @property-read ?FormattedValue $feeTotal Sum of the fees
 * @property-read ?FormattedValue $interestTotal Sum of the interest
 * @property-read ?FormattedValue $total Total to pay
 */
final class DunningView extends ViewNode {}
