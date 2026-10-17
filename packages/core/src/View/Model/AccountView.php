<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * A credit transfer account (BG-17).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $iban Account identifier (BT-84), IBANs in blocks of four
 * @property-read ?string $bic Payment service provider (BT-86)
 * @property-read ?string $name Account name (BT-85)
 */
final class AccountView extends ViewNode {}
