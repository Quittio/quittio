<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Countries\MandatoryFieldList;
use Quittio\Core\Data\Presentation\Colors;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Documents\DocumentCalculator;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Pdf\LogoCache;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Quittio;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Templates\PhpTemplateEngine;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\View\MandatoryFieldGuard;
use Quittio\Core\View\MandatoryFieldMissing;
use Quittio\Core\View\Markdown;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;

/*
| Templates, the mandatory field guard and the safety rules of the human-readable invoice.
*/

it('resolves template names only inside the template directories', function (string $name) {
    $dir = sys_get_temp_dir().'/quittio-names-'.bin2hex(random_bytes(4));
    mkdir($dir.'/nested', 0775, true);
    file_put_contents($dir.'/nested/ok.php', 'ok');
    file_put_contents(dirname($dir).'/outside-'.basename($dir).'.php', '<?php echo "executed";');
    $view = (new ViewModelFactory)->build((new En16931Calculator)->calculate(Invoices::simple()->build()));

    expect((new PhpTemplateEngine([$dir]))->render('nested/ok', $view))->toBe('ok');
    (new PhpTemplateEngine([$dir]))->render(str_replace('{outside}', '../outside-'.basename($dir), $name), $view);
})->with([
    'absolute path' => ['/etc/hosts'],
    'parent directory' => ['{outside}'],
    'dot env' => ['../.env'],
    'stream wrapper' => ['php://filter/resource=/etc/hosts'],
    'file name with extension' => ['nested/ok.php'],
])->throws(InvalidArgumentException::class);

it('watermarks no run that produces e-invoice XML, also next to a PDF', function (array $formats) {
    Quittio::create()->render(Invoices::simple()->build(), new RenderOptions(formats: $formats, options: ['watermark' => 'ENTWURF']));
})->with([
    'pdf and xrechnung' => [['pdf', 'cii']],
    'xml only' => [['ubl']],
    'zugferd' => [['zugferd']],
])->throws(ConfigurationException::class, 'cannot be rendered with a watermark');

it('merges the presentation of the call over the configured design', function () {
    $quittio = Quittio::create(['presentation' => new Presentation(colors: new Colors(primary: '#abcdef'), introText: 'Configured intro', paper: 'A5')]);
    $result = $quittio->render(Invoices::simple()->build(), new RenderOptions(formats: ['html'], presentation: new Presentation(watermark: 'ENTWURF')));
    $pdf = $quittio->render(Invoices::simple()->build(), new RenderOptions(formats: ['pdf'], presentation: new Presentation(watermark: 'ENTWURF')))->pdf();

    expect($result->html())->toContain('#abcdef')
        ->and($result->html())->toContain('Configured intro')
        ->and($result->html())->toContain('ENTWURF')
        ->and($pdf)->toContain('/MediaBox [0 0 419.530 595.280]');
});

it('lets an explicitly configured paper size win over the country default, also A4', function () {
    $pdf = Quittio::create(['options' => ['paper' => 'Letter']])->render(Invoices::simple()->build(), new RenderOptions(formats: ['pdf']))->pdf();

    expect($pdf)->toContain('/MediaBox [0 0 612.000 792.000]');
});

it('prints descriptions and notes as plain text unless Markdown is enabled', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::base()
        ->line(fn ($l) => $l->name('Box')->description("Size 20*30*40 cm, screw <M8>\nRAL 9010")->quantity(1, 'H87')->netPrice('10.00')->vat(VatCategory::StandardRate, '19'))
        ->note('Offer <A-17>, *free* delivery')
        ->build());
    $plain = (new ViewModelFactory)->build($invoice);
    $markdown = (new ViewModelFactory)->build($invoice, new ViewContext(options: ['markdown' => true]));

    expect($plain->lines[0]->description->html)->toBe("Size 20*30*40 cm, screw &lt;M8&gt;<br>\nRAL 9010")
        ->and($plain->notes[0]->html)->toBe('Offer &lt;A-17&gt;, *free* delivery')
        ->and($markdown->lines[0]->description->html)->toContain('&lt;M8&gt;')
        ->and($markdown->notes[0]->html)->toBe('<p>Offer &lt;A-17&gt;, <em>free</em> delivery</p>')
        ->and(Markdown::toHtml('<img src=x onerror=alert(1)>'))->toBe('&lt;img src=x onerror=alert(1)&gt;');
});

it('accepts only hex colours, plain font names and known layouts and QR code types', function (Presentation $presentation) {
    (new ViewModelFactory)->build((new En16931Calculator)->calculate(Invoices::simple()->build()), new ViewContext(defaults: $presentation));
})->with([
    'colour' => [new Presentation(colors: new Colors(primary: 'red; background: url(http://x)'))],
    'font' => [new Presentation(font: 'inter; } body { display: none')],
    'layout' => [new Presentation(letterLayout: 'din5008-typo')],
    'payment QR code' => [new Presentation(paymentQr: 'giro-code')],
])->throws(ConfigurationException::class);

it('lays out self-billed invoices from the buyer to the seller without a payment request', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->type(InvoiceTypeCode::SelfBilledInvoice)->paymentTerms(fn ($t) => $t->dueInDays(14)->cashDiscount(7, '2'))->build());
    $view = (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de'));
    $html = (new PhpTemplateEngine)->render('default', $view);

    expect($view->issuer->name)->toBe($invoice->buyer->name)
        ->and($view->recipient->name)->toBe($invoice->seller->name)
        ->and($view->design->senderLine)->toStartWith($invoice->buyer->name)
        ->and($view->payment->qrCode)->toBeNull()
        ->and($view->payment->isPayout)->toBeTrue()
        ->and($view->totals->amountDueLabel)->toBe('Auszahlungsbetrag')
        ->and($html)->not->toContain('Bitte zahlen Sie')
        ->and($html)->not->toContain('gewähren wir')
        ->and($html)->toContain('Wir überweisen den Betrag')
        ->and($html)->toContain('Auszahlung auf Ihr Konto')
        ->and(implode(' ', $view->design->footerColumns[0]->toArray()))->toContain($invoice->buyer->name)
        ->and((new MandatoryFieldGuard)->check(MandatoryFieldGuard::baseFields($invoice), $view->accessLog()))->toBe([]);
});

it('labels negative invoices as refunds and hides the bank details also in the footer', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::base()->line(fn ($l) => $l->name('Cancellation')->quantity(-1, 'H87')->netPrice('100.00')->vat(VatCategory::StandardRate, '19'))->build());
    $view = (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de'));
    $footer = implode(' ', array_merge(...array_map(static fn (array $c): array => $c, $view->design->footerColumns->toArray())));

    expect($view->totals->amountDueLabel)->toBe('Erstattungsbetrag')
        ->and($view->payment->isRefund)->toBeTrue()
        ->and($footer)->not->toContain('IBAN');
});

it('states margin schemes without a separate VAT amount', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::base()->line(fn ($l) => $l->name('Used car')->quantity(1, 'H87')->netPrice('5000.00')->vat(VatCategory::Exempt, '0', 'Gebrauchtgegenstände/Sonderregelung', 'VATEX-EU-F'))->build());
    $view = (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de'));
    $html = (new PhpTemplateEngine)->render('default', $view);

    expect($view->vatRows[0]->amount)->toBeNull()
        ->and($view->vatRows[0]->label)->toStartWith('Sonderregelung, kein gesonderter Steuerausweis')
        ->and($html)->not->toContain('Umsatzsteuerfrei')
        ->and($html)->toContain('Gebrauchtgegenstände/Sonderregelung')
        ->and((new MandatoryFieldGuard)->check(MandatoryFieldGuard::baseFields($invoice), $view->accessLog()))->toBe([]);
});

it('puts the sender line at the top of the address field and the recipient below it', function () {
    $view = (new ViewModelFactory)->build((new En16931Calculator)->calculate(Invoices::simple()->build()));
    $html = (new PhpTemplateEngine)->render('default', $view);

    // The zones of DIN 5008 stay available to own templates.
    expect($view->design->addressZoneTop)->toBe(17.7)
        ->and($view->design->noticeZoneHeight)->toBe(12.7)
        ->and($html)->toContain('.address { position: absolute; top: 45mm; left: 20mm; width: 85mm; height: 45mm;')
        ->and($html)->toMatch('#<div class="address">\s*<div class="sender-line">[^<]+</div>\s*<div class="address-lines">#');
});

it('requires every printed line, VAT row, note and the cash discount', function (string $template, string $missing) {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()
        ->line(fn ($l) => $l->name('Book')->quantity(1, 'H87')->netPrice('20.00')->vat(VatCategory::StandardRate, '7'))
        ->note('Regulatory note', 'REG')
        ->note('Second regulatory note', 'TXD')
        ->paymentTerms(fn ($t) => $t->dueInDays(14)->cashDiscount(7, '2'))
        ->build());
    $dir = sys_get_temp_dir().'/quittio-guard-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $full = file_get_contents(dirname(__DIR__, 3).'/resources/templates/default.php');
    file_put_contents($dir.'/custom.php', $template === 'default' ? $full : $template);
    $view = (new ViewModelFactory)->build($invoice);
    (new PhpTemplateEngine([$dir]))->render('custom', $view);

    $check = static fn () => (new MandatoryFieldGuard)->check(MandatoryFieldGuard::baseFields($invoice), $view->accessLog());
    if ($missing === '') {
        expect($check())->toBe([]);
    } else {
        expect($check)->toThrow(MandatoryFieldMissing::class, $missing);
    }
})->with([
    'default template' => ['default', ''],
    'only the first line' => ['<?= $e($invoice->lines[0]->name) ?>', 'Item name of every line (BT-153) [lines[1].name'],
    'only the first VAT row' => ['<?= $e($invoice->vatRows[0]->label) ?><?= $e($invoice->vatRows[0]->amount) ?>', 'VAT amount per VAT category and rate (BT-117) [vatRows[1].amount]'],
    'only the first legal note' => ['<?= $e($invoice->legalNotes[0]) ?>', '[legalNotes[1]]'],
    'no cash discount' => ['<?= $e($invoice->paymentTerms->lines[0]) ?>', 'Cash discount terms (BT-20)'],
    'buyer name without address' => ['<?= $e($invoice->buyer->name) ?>', 'Buyer address (BG-8)'],
    'sender line instead of the seller address' => ['<?= $e($invoice->design->senderLine) ?>', 'Seller address (BG-5)'],
]);

it('does not count reads of null values and maps issuer and recipient onto seller and buyer', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->build());
    $view = (new ViewModelFactory)->build($invoice);
    $view->taxRepresentative;
    foreach ($view->issuer->address->lines as $line) {
        echo '';
    }
    $view->recipient->name;
    $log = $view->accessLog();

    expect($log->accessed('taxRepresentative'))->toBeFalse()
        ->and($log->accessed('seller.address.lines[0]'))->toBeTrue()
        ->and($log->accessed('buyer.name'))->toBeTrue()
        ->and($log->expand('lines[*].name'))->toBe(['lines[0].name'])
        ->and($log->expand('taxRepresentative.vatId'))->toBe([])
        ->and((new MandatoryFieldGuard)->check(new MandatoryFieldList([new MandatoryField('taxRepresentative.vatId', 'Not present')]), $log))->toBe([]);
});

it('flattens raster logos and rasterises SVG logos for PDF/A', function () {
    $dir = sys_get_temp_dir().'/quittio-pdfa-logo-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $image = imagecreatetruecolor(1200, 300);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagepng($image, $dir.'/alpha.png');
    $small = imagecreatetruecolor(60, 20);
    imagesavealpha($small, true);
    ob_start();
    imagepng($small);
    $dataUri = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    file_put_contents($dir.'/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="60"><rect width="200" height="60" fill="#1e40af" opacity="0.5"/></svg>');
    $cache = new LogoCache($dir.'/cache', pdfa: true);
    $colorType = static fn (string $file): int => ord((string) file_get_contents($file, false, null, 25, 1));

    $png = (string) $cache->resolve($dir.'/alpha.png');
    $data = (string) $cache->resolve($dataUri);
    expect(array_slice((array) getimagesize($png), 0, 2))->toBe([600, 150])
        ->and($colorType($png))->toBe(2)
        ->and($colorType($data))->toBe(2)
        ->and((new LogoCache($dir.'/cache'))->resolve($dataUri))->toBe($dataUri);

    if (extension_loaded('imagick')) {
        $svg = (string) $cache->resolve($dir.'/logo.svg');
        expect($colorType($svg))->toBe(2);
        file_put_contents($dir.'/external.svg', '<svg xmlns="http://www.w3.org/2000/svg"><image href="file:///etc/hosts"/></svg>');
        expect(fn () => $cache->resolve($dir.'/external.svg'))->toThrow(ConfigurationException::class, 'external resources');
    }
})->skip(! extension_loaded('gd'), 'GD is not available.');

it('keeps fx:Version 1.0 and factur-x.xml for the Factur-X profiles', function () {
    $pdf = Quittio::create()->render(Invoices::simple()->build(), new RenderOptions(formats: ['zugferd']))->pdf();

    expect($pdf)->toContain('<fx:Version>1.0</fx:Version>')
        ->and($pdf)->toContain('<fx:ConformanceLevel>EN 16931</fx:ConformanceLevel>')
        ->and((new PdfEmbeddedFileExtractor)->extract($pdf)[0] ?? null)->toBe('factur-x.xml');
});

it('asks for the reminder total with the open invoice numbers in the GiroCode', function () {
    $reminder = DocumentBuilder::paymentReminder(2)
        ->number('MA-1')
        ->issueDate('2026-10-01')
        ->seller(Invoices::simple()->build()->seller)
        ->buyer(fn ($b) => $b->name('Buyer')->address('Street 1', '10115', 'Berlin', 'DE'))
        ->creditTransfer('DE02120300000000202051', 'BYLADEM1001', 'Seller Ltd')
        ->openItem('RE-1', '2026-08-01', '119.00', '2026-08-15', '19.00')
        ->openItem('RE-2', '2026-08-05', '50.00', '2026-08-19')
        ->fee('Mahngebühr', '5.00')
        ->interest('100.00', '9.12', '2026-08-16', '2026-10-01')
        ->build();
    $document = (new DocumentCalculator(new En16931Calculator))->calculate($reminder);
    $view = (new ViewModelFactory)->buildDocument($document, new ViewContext(locale: 'de'));
    $total = $document->dunning?->total('EUR')->getAmount()->toScale(2)->toString();
    $payload = explode("\n", (string) $view->payment->qrCode->payload);

    expect($payload[7])->toBe('EUR'.$total)
        ->and(end($payload))->toBe('RE-1, RE-2')
        ->and($view->payment->remittance)->toBe('RE-1, RE-2')
        // The interest period ends the day before "until" (exclusive).
        ->and($view->documentInfo->dunning->interest[0]->description)->toContain('30.09.2026');
});
