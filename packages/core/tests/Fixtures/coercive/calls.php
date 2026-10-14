<?php

declare(strict_types=1);

// Deliberately without strict types: application code usually runs in PHP's coercive typing
// mode, where an int|string parameter silently turns 19.99 into 19. Excluded from Pint
// (pint.json), which would add the declaration.

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Tax\CustomTaxPreset;

return [
    'quantity' => fn () => (new LineBuilder)->quantity(2.5),
    'net price' => fn () => (new LineBuilder)->netPrice(19.99),
    'base quantity' => fn () => (new LineBuilder)->netPrice('19.99', 1.5),
    'list price' => fn () => (new LineBuilder)->listPrice(29.99),
    'price discount' => fn () => (new LineBuilder)->listPrice('29.99', 2.5),
    'price incl. VAT' => fn () => (new LineBuilder)->priceInclVat(23.79),
    'line VAT rate' => fn () => (new LineBuilder)->vat(VatCategory::StandardRate, 19.5),
    'exchange rate' => fn () => InvoiceBuilder::make()->exchangeRate(1.0856),
    'tax currency rate' => fn () => InvoiceBuilder::make()->taxCurrency('EUR', 1.0856),
    'allowance VAT rate' => fn () => InvoiceBuilder::make()->allowance(VatCategory::StandardRate, 7.5, '10.00'),
    'charge VAT rate' => fn () => InvoiceBuilder::make()->charge(VatCategory::StandardRate, 7.5, '10.00'),
    'custom preset rate' => fn () => CustomTaxPreset::make('reduced-ch', VatCategory::StandardRate, 2.6),
    'line net price' => fn (InvoiceLine $line) => $line->withNetPrice(19.99),
    'line price incl. VAT' => fn (InvoiceLine $line) => $line->withPriceInclVat(23.79),
];
