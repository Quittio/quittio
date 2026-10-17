<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * A preceding invoice reference (BG-3).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $number Preceding invoice number (BT-25)
 * @property-read ?FormattedValue $issueDate Its issue date (BT-26)
 */
final class PrecedingInvoiceView extends ViewNode {}
