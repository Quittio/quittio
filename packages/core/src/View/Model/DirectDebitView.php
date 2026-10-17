<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * A direct debit (BG-19).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read ?string $mandate Mandate reference (BT-89)
 * @property-read ?string $creditorId Creditor identifier (BT-90)
 * @property-read ?string $account Debited account (BT-91)
 */
final class DirectDebitView extends ViewNode {}
