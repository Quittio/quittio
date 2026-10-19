<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * The payment slip of a payment QR code, e.g. the payment part and receipt of the Swiss QR bill.
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $title Title of the payment part, e.g. "Zahlteil"
 * @property-read string $receiptTitle Title of the receipt, e.g. "Empfangsschein"
 * @property-read ViewList<PaymentSlipSectionView> $sections Information section of the payment part
 * @property-read ViewList<PaymentSlipSectionView> $receiptSections Information section of the receipt
 * @property-read string $currencyHeading Heading of the currency
 * @property-read string $amountHeading Heading of the amount
 * @property-read string $currency Currency code
 * @property-read ?string $amount Formatted amount; null for an empty field
 * @property-read string $acceptancePoint Text of the acceptance point of the receipt
 * @property-read string $separationNote Note above the separation line
 * @property-read ?string $mark Image (data URI) in the middle of the code
 * @property-read string $dataUri The code without quiet zone, for 46 × 46 mm
 */
final class PaymentSlipView extends ViewNode {}
