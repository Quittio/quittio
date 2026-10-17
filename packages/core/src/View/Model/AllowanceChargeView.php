<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * An allowance or charge on document level (BG-20, BG-21).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read ?string $reason Reason text or code
 * @property-read ?FormattedValue $amount Amount
 * @property-read ?FormattedValue $percent Percentage
 * @property-read ?FormattedValue $base Base amount
 * @property-read ?FormattedValue $vatRate VAT rate
 * @property-read string $vatCategory VAT category code
 * @property-read string $label Ready text, with several VAT groups followed by the VAT rate or category
 */
final class AllowanceChargeView extends ViewNode {}
