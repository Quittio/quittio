<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * The unit of measure of a line.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $code Unit code (BT-130)
 * @property-read string $label Name of the unit in the locale
 */
final class UnitView extends ViewNode {}
