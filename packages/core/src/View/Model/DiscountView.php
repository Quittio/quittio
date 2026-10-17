<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * A cash discount of the payment terms.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read int $days Days after the issue date
 * @property-read string $percent Formatted percentage
 * @property-read ?FormattedValue $until Last day of the discount; null when nothing is payable
 * @property-read ?FormattedValue $amount Amount after the discount; null when nothing is payable
 * @property-read string $text Ready text
 */
final class DiscountView extends ViewNode {}
