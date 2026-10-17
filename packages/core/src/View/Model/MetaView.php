<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * Locale, custom values and source of the invoice.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $locale Locale of the run
 * @property-read ViewNode $custom Values of ->custom()
 * @property-read ?string $source Source of the invoice data
 */
final class MetaView extends ViewNode {}
