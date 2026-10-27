<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Qr;

use Quittio\Core\Data\InvoiceData;

/**
 * Creates the payload of a payment QR code (GiroCode, Swiss QR bill, …).
 */
interface PaymentQrGenerator
{
    /**
     * Payment QR code type of the generator, e.g. "girocode" or "swiss-qr-bill". A presentation
     * that asks for this type uses the generator of the country pack with this key.
     */
    public function key(): string;

    /**
     * Whether the invoice qualifies for the code (currency, payment means, amount due …). Without
     * it no code is printed, unless an explicit amount is given (payment reminders).
     */
    public function supports(InvoiceData $invoice): bool;

    /**
     * Text content of the QR code, or null if no code can be created.
     *
     * @param  array<string, mixed>  $options
     */
    public function payload(InvoiceData $invoice, array $options = []): ?string;
}
