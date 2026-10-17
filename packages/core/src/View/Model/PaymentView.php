<?php

declare(strict_types=1);

namespace Quittio\Core\View\Model;

use Quittio\Core\View\ViewNode;

/**
 * The payment instructions (BG-16).
 *
 * A typed, read-only node of the view model: object syntax and array syntax read the same
 * value, and every read is recorded for the mandatory field guard (see ViewNode).
 *
 * @property-read string $meansCode Payment means code (BT-81)
 * @property-read string $means Name of the payment means
 * @property-read ?string $meansText Payment means text (BT-82)
 * @property-read string $remittance Remittance information (BT-83), otherwise the invoice number
 * @property-read ViewList<AccountView> $accounts Credit transfer accounts (BG-17)
 * @property-read ?CardView $card Payment card (BG-18)
 * @property-read ?DirectDebitView $directDebit Direct debit (BG-19)
 * @property-read ?QrCodeView $qrCode Payment QR code
 * @property-read bool $isRefund Credit note or negative amount due
 * @property-read bool $isPayout Self-billed invoice: the accounts receive the payout
 */
final class PaymentView extends ViewNode {}
