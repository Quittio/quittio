<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * A payment card (BG-18).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $number Last digits of the card number, e.g. "•••• 1234"
 * @property-read ?string $holder Card holder name (BT-88)
 */
final class CardView extends ViewNode {}
