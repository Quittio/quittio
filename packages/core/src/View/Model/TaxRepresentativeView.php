<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * The seller tax representative (BG-11).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $name Name (BT-62)
 * @property-read string $vatId VAT identifier (BT-63)
 * @property-read AddressView $address Postal address (BG-12)
 */
final class TaxRepresentativeView extends ViewNode {}
