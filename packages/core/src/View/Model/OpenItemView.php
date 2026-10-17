<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * An open invoice of a reminder or statement.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $invoiceNumber Invoice number
 * @property-read ?FormattedValue $invoiceDate Invoice date
 * @property-read ?FormattedValue $dueDate Due date
 * @property-read ?string $description Description
 * @property-read ?FormattedValue $amount Invoice amount
 * @property-read ?FormattedValue $paid Paid amount
 * @property-read ?FormattedValue $open Open amount
 */
final class OpenItemView extends ViewNode {}
