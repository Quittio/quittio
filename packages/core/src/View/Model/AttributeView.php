<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * An item attribute (BG-32).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $name Attribute name (BT-160)
 * @property-read string $value Attribute value (BT-161)
 */
final class AttributeView extends ViewNode {}
