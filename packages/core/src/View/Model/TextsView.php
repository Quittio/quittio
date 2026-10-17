<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * The texts of the presentation.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read ?string $salutation Salutation (plain text)
 * @property-read string $intro Intro text as HTML from Markdown
 * @property-read string $closing Closing text as HTML from Markdown
 */
final class TextsView extends ViewNode {}
