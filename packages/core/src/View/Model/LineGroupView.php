<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * A group of lines for display.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read ?string $id Group identifier; null for the lines without a group
 * @property-read ?string $title Group title
 * @property-read ViewList<LineView> $lines Lines of the group, with carryOver
 * @property-read ?FormattedValue $subtotal Sum of the line net amounts when the group shows a subtotal
 */
final class LineGroupView extends ViewNode {}
