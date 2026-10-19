<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * One heading of a payment slip with its lines; without lines an empty field to fill in by hand.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $heading Heading, e.g. "Konto / Zahlbar an"
 * @property-read ViewList<string> $lines Values
 */
final class PaymentSlipSectionView extends ViewNode {}
