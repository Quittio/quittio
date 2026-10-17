<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * A note (BG-1) other than the legal notes.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read ?string $subject Subject code (BT-21)
 * @property-read string $content Note (BT-22)
 * @property-read string $html Escaped plain text with line breaks, or Markdown with the option "markdown"
 */
final class NoteView extends ViewNode {}
