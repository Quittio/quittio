<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * An account statement.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read FormattedValue $period Statement period
 * @property-read ViewList<OpenItemView> $items Items
 * @property-read ?FormattedValue $openingBalance Opening balance
 * @property-read ?FormattedValue $balance Balance
 */
final class StatementView extends ViewNode {}
