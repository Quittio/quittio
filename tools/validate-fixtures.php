<?php

declare(strict_types=1);

/**
 * Writes every example scenario in every applicable syntax and profile to build/fixtures,
 * and renders the scenarios with EN 16931 as ZUGFeRD PDF. tools/validate-fixtures.sh validates
 * the XML files with KoSIT and the PDFs with veraPDF.
 */

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30;
use Quittio\Country\DE\Profiles\XRechnung\XRechnung30Extension;

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

echo "Wrote {$count} files to {$target}\n";
