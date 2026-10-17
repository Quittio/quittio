<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * An attached document (BG-24).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $id Identifier (BT-122)
 * @property-read ?string $description Description (BT-123)
 * @property-read ?string $filename File name of an embedded document
 * @property-read ?string $uri External location (BT-124)
 */
final class AttachmentView extends ViewNode {}
