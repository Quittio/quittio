<?php

declare(strict_types=1);

namespace Quittio\Core\Tests\Support;

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;

/**
 * Builders for test invoices without a dependency on a country pack.
 */
final class Invoices
{
    public static function base(): InvoiceBuilder
    {
        return InvoiceBuilder::make()
            ->number('INV-2026-0001')
            ->issueDate('2026-10-03')
            ->currency('EUR')
            ->seller(fn ($s) => $s
                ->name('Seller Ltd')
                ->vatId('DE123456789')
                ->address('Main Street 1', '12345', 'Sample City', 'DE')
                ->contact('Jane Seller', '+49 30 123456', 'billing@seller.example')
                ->electronicAddress('billing@seller.example', 'EM'))
            ->buyer(fn ($b) => $b
                ->name('Buyer Inc')
                ->vatId('DE987654321')
                ->address('Station Road 5', '54321', 'Example Town', 'DE')
                ->electronicAddress('ap@buyer.example', 'EM'))
            ->deliveryDate('2026-09-30')
            ->paymentTerms(fn ($t) => $t->dueInDays(14))
            ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'DE02120300000000202051', bic: 'BYLADEM1001');
    }

    public static function simple(): InvoiceBuilder
    {
        return self::base()->line(fn ($l) => $l
            ->name('Consulting')
            ->quantity(10, UnitCode::Hour)
            ->netPrice('100.00')
            ->vat(VatCategory::StandardRate, '19'));
    }
}
