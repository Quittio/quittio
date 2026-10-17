<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * A package of a delivery note.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $id Package identifier
 * @property-read ?string $type Package type
 * @property-read ?FormattedValue $weight Weight, e.g. "2.5 kg"
 * @property-read ?string $dimensions Dimensions
 * @property-read ?string $trackingNumber Tracking number
 */
final class PackageView extends ViewNode {}
