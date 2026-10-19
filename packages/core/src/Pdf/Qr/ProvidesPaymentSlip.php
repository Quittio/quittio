<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Qr;

use Quittio\Core\Data\InvoiceData;

/**
 * A payment QR code generator whose code belongs on a payment slip of its own, e.g. the payment
 * part and receipt of the Swiss QR bill. The default templates then print the slip at the bottom
 * of a page of its own instead of the small code next to the bank details.
 */
interface ProvidesPaymentSlip extends PaymentQrGenerator
{
    /**
     * The texts of the slip for the code $payload in the locale; null for none.
     */
    public function slip(InvoiceData $invoice, string $payload, string $locale): ?PaymentSlip;
}
