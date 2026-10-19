<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\ViewNode;

/**
 * The payment QR code.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $type Generator key, e.g. "girocode"
 * @property-read string $payload Content of the code
 * @property-read string $dataUri Image as data URI
 * @property-read ?FormattedValue $amount Amount in the code
 * @property-read ?string $note Note on the amount, e.g. the validity of a discounted amount
 * @property-read ?PaymentSlipView $slip Payment slip of the code (ProvidesPaymentSlip), e.g. of the Swiss QR bill
 */
final class QrCodeView extends ViewNode {}
