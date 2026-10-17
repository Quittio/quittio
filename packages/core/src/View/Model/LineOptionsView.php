<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * The options of a document line.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read bool $optional Optional line
 * @property-read ?string $alternativeTo Line this line is an alternative to
 * @property-read ViewList<string> $serialNumbers Serial numbers
 */
final class LineOptionsView extends ViewNode {}
