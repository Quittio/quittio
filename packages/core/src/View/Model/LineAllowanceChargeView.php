<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * An allowance or charge of a line (BG-27, BG-28).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read ?string $reason Reason text or code
 * @property-read ?FormattedValue $amount Amount
 * @property-read ?FormattedValue $percent Percentage
 * @property-read string $label Ready text, e.g. "Allowance reason 10 %"
 */
final class LineAllowanceChargeView extends ViewNode {}
