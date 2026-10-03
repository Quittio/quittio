<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Qr;

use Quittio\Core\Data\InvoiceData;

/**
 * Creates the payload of a payment QR code (GiroCode, Swiss QR bill, …).
 */
interface PaymentQrGenerator
{
    public function key(): string;

    public function supports(InvoiceData $invoice): bool;

    /**
     * Text content of the QR code, or null if no code can be created.
     *
     * @param  array<string, mixed>  $options
     */
    public function payload(InvoiceData $invoice, array $options = []): ?string;
}
