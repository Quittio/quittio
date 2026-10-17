<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * A filled document reference, e.g. the purchase order.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $key buyer_reference, purchase_order, sales_order, contract, project, despatch_advice, tender, invoiced_object or accounting_reference
 * @property-read string $label Label in the locale
 * @property-read string $value Reference
 */
final class ReferenceView extends ViewNode {}
