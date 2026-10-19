<?php

declare(strict_types=1);

/**
 * Writes every example scenario in every applicable syntax and profile to build/fixtures,
 * and renders the scenarios with EN 16931 as ZUGFeRD PDF. tools/validate-fixtures.sh validates
 * the XML files with KoSIT and the PDFs with veraPDF.
 */

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Corrections\PriceReductionReason;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Quittio;
use Quittio\Core\Tax\TaxPreset;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Country\DE\Builder\GermanInvoice;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30Extension;
use Quittio\Country\DE\Tax\VatPreset;

require dirname(__DIR__).'/vendor/autoload.php';

$target = $argv[1] ?? dirname(__DIR__).'/build/fixtures';
@mkdir($target, 0777, true);

$calculator = new En16931Calculator;
$profiles = ProfileRegistry::withDefaults();
$quittio = Quittio::create([], [new CountryDE]);
$count = 0;

foreach (Scenarios::names() as $name) {
    $invoice = $calculator->calculate(Scenarios::make($name)->build());
    $xrInvoice = $calculator->calculate(Scenarios::make($name)->buyerReference('04011000-12345-03')->build());

    $outputs = match ($name) {
        'sub-lines' => [
            "{$name}.extended.cii.xml" => (new CiiWriter)->serialize($invoice, $profiles->get('extended')),
            "{$name}.xrechnung-extension.ubl.xml" => (new UblWriter)->serialize($xrInvoice->with(['extended' => null]), new XRechnung30Extension),
        ],
        'peppol' => [
            "{$name}.peppol.ubl.xml" => (new UblWriter)->serialize($invoice, $profiles->get('peppol')),
        ],
        default => [
            "{$name}.en16931.cii.xml" => (new CiiWriter)->serialize($invoice, $profiles->get('en16931')),
            "{$name}.en16931.ubl.xml" => (new UblWriter)->serialize($invoice, $profiles->get('en16931')),
            "{$name}.xrechnung.cii.xml" => (new CiiWriter)->serialize($xrInvoice, new XRechnung30),
            "{$name}.xrechnung.ubl.xml" => (new UblWriter)->serialize($xrInvoice, new XRechnung30),
        ],
    };

    if (! in_array($name, ['sub-lines', 'peppol'], true)) {
        $outputs["{$name}.zugferd.pdf"] = $quittio->make(Scenarios::make($name))->format('zugferd')->profile('en16931')->render()->pdf();
    }

    foreach ($outputs as $file => $xml) {
        file_put_contents($target.'/'.$file, $xml);
        $count++;
    }
}

// Documents of the German pack (tax review of October 2026): EN 16931 and XRechnung, CII and UBL.
$line = static fn (string $name, string $net, TaxPreset $vat = VatPreset::Standard): Closure => static fn (LineBuilder $l): LineBuilder => $l->name($name)->quantity(1, UnitCode::Piece)->netPrice($net)->vat($vat);
$de = static fn (InvoiceBuilder $builder): GermanInvoice => new GermanInvoice($builder);
$advance = $calculator->calculate($de(Scenarios::base()->number('AB-2026-0001')->issueDate('2026-09-01')
    ->line($line('Anzahlung Website', '1000.00'))->line($line('Anzahlung Buch', '100.00', VatPreset::Reduced)))
    ->advance('2026-10', receivedOn: '2026-08-28')->prepaid('1177.00')->build());
$original = $calculator->calculate(Scenarios::make('mixed-vat')->build());
$selfBilled = $calculator->calculate($de(Scenarios::make('simple'))->selfBilled()->build());
$final = static fn (): InvoiceBuilder => Scenarios::base()->line($line('Website', '3000.00'))->line($line('Buch', '300.00', VatPreset::Reduced));
/** @var array<string, Closure(): InvoiceBuilder> $german */
$german = [
    'de-small-business-ex' => fn (): InvoiceBuilder => $de(Scenarios::base()->seller(fn (PartyBuilder $s): PartyBuilder => $s->vatId(null)))->smallBusinessId('DE123456789EX')
        ->line($line('Beratung', '500.00', VatPreset::SmallBusiness)),
    'de-advance' => fn (): InvoiceBuilder => InvoiceBuilder::from($advance),
    'de-final-prepaid' => fn (): InvoiceBuilder => $de($final())->deductAdvance($advance, received: GermanInvoice::RECEIVED_IN_FULL),
    'de-final-partial' => fn (): InvoiceBuilder => $de($final())->deductAdvance($advance, received: '600.00'),
    'de-final-remainder' => fn (): InvoiceBuilder => $de($final())->deductAdvance($advance, received: GermanInvoice::RECEIVED_IN_FULL, style: GermanInvoice::REMAINDER),
    'de-self-billed' => fn (): InvoiceBuilder => InvoiceBuilder::from($selfBilled),
    'de-self-billed-cancellation-261' => fn (): InvoiceBuilder => $quittio->cancellation($selfBilled, 'ST-1', strategy: 'credit_note'),
    'de-self-billed-cancellation-389' => fn (): InvoiceBuilder => $quittio->cancellation($selfBilled, 'ST-1', strategy: 'negative_invoice'),
    'de-correction' => fn (): InvoiceBuilder => $quittio->correction($original, 'RE-2026-0050', reason: 'Falscher Preis'),
    'de-price-reduction' => fn (): InvoiceBuilder => $quittio->priceReduction($original, 'EM-2026-0001', PriceReductionReason::CashDiscount, percent: '2'),
    'de-business-transfer' => fn (): InvoiceBuilder => Scenarios::base()->seller(fn (PartyBuilder $s): PartyBuilder => $s->vatId(null))->buyer(fn (PartyBuilder $b): PartyBuilder => $b->vatId(null))
        ->line($line('Betrieb mit Inventar', '250000.00', VatPreset::BusinessTransfer)),
    'de-photovoltaic' => fn (): InvoiceBuilder => Scenarios::base()->line($line('Photovoltaikanlage', '12000.00', VatPreset::ZeroRatedPv)),
    'de-margin-scheme' => fn (): InvoiceBuilder => Scenarios::base()->line($line('Gebrauchtwagen', '8000.00', VatPreset::MarginSecondHand)),
];
foreach ($german as $name => $build) {
    $invoice = $calculator->calculate($build()->build());
    $xrInvoice = $calculator->calculate($build()->buyerReference('04011000-12345-03')->build());
    $outputs = [
        "{$name}.en16931.cii.xml" => (new CiiWriter)->serialize($invoice, $profiles->get('en16931')),
        "{$name}.en16931.ubl.xml" => (new UblWriter)->serialize($invoice, $profiles->get('en16931')),
        "{$name}.xrechnung.cii.xml" => (new CiiWriter)->serialize($xrInvoice, new XRechnung30),
        "{$name}.xrechnung.ubl.xml" => (new UblWriter)->serialize($xrInvoice, new XRechnung30),
        "{$name}.zugferd.pdf" => $quittio->make($build())->format('zugferd')->profile('en16931')->validate(false)->render()->pdf(),
    ];
    foreach ($outputs as $file => $xml) {
        file_put_contents($target.'/'.$file, $xml);
        $count++;
    }
}

echo "Wrote {$count} files to {$target}\n";
