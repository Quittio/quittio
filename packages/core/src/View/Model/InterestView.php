<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * An interest charge of a reminder.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $description Description or the generated text
 * @property-read int $days Days with interest
 * @property-read ?FormattedValue $amount Interest amount
 */
final class InterestView extends ViewNode {}
