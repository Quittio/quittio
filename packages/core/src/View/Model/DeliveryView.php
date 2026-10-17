<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * Delivery information (BG-13).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read ?string $recipientName Deliver to party name (BT-70)
 * @property-read ?FormattedValue $date Actual delivery date (BT-72)
 * @property-read ?AddressView $address Deliver to address (BG-15) with the recipient name as first line
 */
final class DeliveryView extends ViewNode {}
