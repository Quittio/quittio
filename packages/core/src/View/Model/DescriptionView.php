<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * The item description of a line.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read ?string $text Description (BT-154) as in the XML
 * @property-read string $html Escaped plain text with line breaks, or Markdown with the option "markdown"
 */
final class DescriptionView extends ViewNode {}
