<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * The payee (BG-10).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $name Name (BT-59)
 * @property-read ?string $identifier Identifier (BT-60)
 * @property-read ?string $legalRegistration Legal registration identifier (BT-61)
 */
final class PayeeView extends ViewNode {}
