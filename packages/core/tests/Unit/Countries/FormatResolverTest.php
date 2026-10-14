<?php

declare(strict_types=1);

use Quittio\Core\Countries\AbstractCountryPack;
use Quittio\Core\Countries\CountryGeneric;
use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Formats\DefaultFormatResolver;
use Quittio\Core\Formats\FormatChoice;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Formats\FormatRule;
use Quittio\Core\Tests\Support\Invoices;

/**
 * A pack whose only rule sends invoices to public buyers (buyer reference "PUBLIC") as XRechnung.
 */
function publicBuyerPack(string $code): CountryPack
{
    return new class($code) extends AbstractCountryPack
    {
        public function __construct(private readonly string $code) {}

        public function code(): string
        {
            return $this->code;
        }

        public function formatRules(): array
        {
            return [new class implements FormatRule
            {
                public function resolve(InvoiceData $invoice): ?FormatChoice
                {
                    return $invoice->buyerReference === 'PUBLIC'
                        ? new FormatChoice(FormatRegistry::XRECHNUNG_CII, 'xrechnung', 'Public buyer.')
                        : null;
                }
            }];
        }
    };
}

function resolveFormat(string $sellerCountry, string $buyerCountry, ?CountryPack $leading = null, ?CountryPack $buyer = null, ?string $sellerPostcode = '10115', ?string $buyerPostcode = '1000', bool $public = false, bool $consumer = false): FormatChoice
{
    $invoice = Invoices::simple()
        ->seller(fn ($s) => $s->name('Seller')->address('Main Street 1', $sellerPostcode, 'City', $sellerCountry))
        ->buyer(fn ($b) => $b->name('Buyer')->address('Station Road 5', $buyerPostcode, 'Town', $buyerCountry)->consumer($consumer))
        ->buyerReference($public ? 'PUBLIC' : null)
        ->build();

    return (new DefaultFormatResolver)->resolve($invoice, new ResolvedCountries($leading ?? new CountryGeneric($sellerCountry), $buyer));
}

it('applies the buyer pack rules for sellers outside the EU', function (string $seller) {
    $choice = resolveFormat($seller, 'DE', buyer: publicBuyerPack('DE'), public: true);

    expect($choice->format)->toBe(FormatRegistry::XRECHNUNG_CII)
        ->and($choice->profile)->toBe('xrechnung');
})->with(['CH', 'GB', 'NO', 'XI', 'US']);

it('applies the buyer pack rules within the EU', function () {
    expect(resolveFormat('AT', 'DE', buyer: publicBuyerPack('DE'), public: true)->format)->toBe(FormatRegistry::XRECHNUNG_CII);
});

it('does not apply buyer pack rules to consumers', function () {
    expect(resolveFormat('CH', 'DE', buyer: publicBuyerPack('DE'), public: true, consumer: true)->format)->toBe(FormatRegistry::PDF);
});

it('falls back to PDF outside the EU VAT territory and names the side', function (string $seller, string $buyer, string $reason) {
    $choice = resolveFormat($seller, $buyer, buyer: publicBuyerPack($buyer));

    expect($choice->format)->toBe(FormatRegistry::PDF)
        ->and($choice->reason)->toBe($reason);
})->with([
    'seller outside' => ['CH', 'DE', 'Invoice from a seller outside the EU VAT territory.'],
    'buyer outside' => ['DE', 'CH', 'Invoice to a buyer outside the EU VAT territory.'],
    'both outside' => ['CH', 'GB', 'Invoice between parties outside the EU VAT territory.'],
]);

it('treats Monaco as France and Northern Ireland as EU, the Isle of Man as outside', function (string $buyer, string $format) {
    expect(resolveFormat('DE', $buyer, buyerPostcode: null)->format)->toBe($format);
})->with([
    'Monaco' => ['MC', FormatRegistry::ZUGFERD],
    'Northern Ireland' => ['XI', FormatRegistry::ZUGFERD],
    'France' => ['FR', FormatRegistry::ZUGFERD],
    'Isle of Man' => ['IM', FormatRegistry::PDF],
    'United Kingdom' => ['GB', FormatRegistry::PDF],
]);

it('treats Monaco as domestic for a French seller', function () {
    expect(resolveFormat('FR', 'MC', buyerPostcode: '98000')->reason)->toBe('Domestic invoice without a country specific rule.');
});

it('treats Büsingen and Heligoland as outside the EU VAT territory', function (string $postcode) {
    $choice = resolveFormat('DE', 'DE', buyerPostcode: $postcode);

    expect($choice->format)->toBe(FormatRegistry::PDF)
        ->and($choice->reason)->toBe('Invoice to a buyer outside the EU VAT territory.');
})->with(['78266', '27498']);

it('lets the seller pack decide for its own excluded territories', function () {
    // Public buyers in Büsingen still receive the format the German pack requires.
    expect(resolveFormat('DE', 'DE', leading: publicBuyerPack('DE'), buyerPostcode: '78266', public: true)->format)
        ->toBe(FormatRegistry::XRECHNUNG_CII);
});
