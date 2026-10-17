<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * The payment terms (BT-20) with due date and cash discounts.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read ViewList<string> $lines Ready texts
 * @property-read ViewList<DiscountView> $discounts Cash discounts
 * @property-read ?FormattedValue $dueDate Due date (BT-9)
 */
final class PaymentTermsView extends ViewNode {}
