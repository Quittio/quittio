<?php

declare(strict_types=1);

use Brick\Money\Money;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Countries\MandatoryFieldList;
use Quittio\Core\Data\Presentation\Colors;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Templates\PhpTemplateEngine;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\IntlFormatter;
use Quittio\Core\View\InvoiceViewModel;
use Quittio\Core\View\MandatoryFieldGuard;
use Quittio\Core\View\MandatoryFieldMissing;
use Quittio\Core\View\Markdown;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;

function viewModel(string $locale = 'en', ?Presentation $presentation = null): InvoiceViewModel
{
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->build());

    return (new ViewModelFactory)->build($invoice, new ViewContext(locale: $locale, defaults: $presentation));
}

/**
 * ICU uses non-breaking spaces in formatted numbers.
 */
function spaces(string $value): string
{
    return (string) preg_replace('/\p{Zs}/u', ' ', $value);
}

function templateDir(string $body): string
{
    $dir = sys_get_temp_dir().'/quittio-templates-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/custom.php', $body);

    return $dir;
}

it('offers formatted and raw values', function () {
    $view = viewModel('de');
    $due = $view->totals->amountDue;

    expect($due)->toBeInstanceOf(FormattedValue::class)
        ->and(spaces((string) $due))->toBe('1.190,00 €')
        ->and($due->raw->getAmount()->toString())->toBe('1190.00')
        ->and((string) $view->issueDate)->toBe('03.10.2026')
        ->and($view->title)->toBe('Rechnung')
        ->and($view->title())->toBe('Rechnung');
});

it('formats numbers per locale', function () {
    $formatter = new IntlFormatter;

    expect(spaces($formatter->percent(Brick\Math\BigDecimal::of('19'), 'de')))->toBe('19 %')
        ->and($formatter->quantity(Brick\Math\BigDecimal::of('1234.5'), 'en'))->toBe('1,234.5');
})->skip(! extension_loaded('intl'), 'intl is not available.');

it('takes per invoice label overrides', function () {
    $view = viewModel('en', new Presentation(labels: ['amount_due' => 'Please pay']));

    expect($view->label('amount_due'))->toBe('Please pay')
        ->and($view->label('preceding_invoice_text', ['number' => 'A-1', 'date' => 'today']))->toBe('Reference: invoice A-1 of today');
});

it('renders a safe Markdown subset', function () {
    expect(Markdown::toHtml("**bold** and *em*\n\n- one\n- two"))->toBe("<p><strong>bold</strong> and <em>em</em></p>\n<ul>\n<li>one</li>\n<li>two</li>\n</ul>")
        ->and(Markdown::toHtml('<script>alert(1)</script>Text'))->not->toContain('<script')
        ->and(Markdown::toHtml('[link](javascript:alert(1))'))->not->toContain('javascript')
        ->and(Markdown::toHtml(null))->toBe('');
});

it('passes the guard with the default template', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->build());
    $view = (new ViewModelFactory)->build($invoice);
    (new PhpTemplateEngine)->render('default', $view);

    expect((new MandatoryFieldGuard)->check(MandatoryFieldGuard::baseFields($invoice), $view->accessLog()))->toBe([]);
});

it('stops templates that omit mandatory values', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->build());
    $view = (new ViewModelFactory)->build($invoice);
    (new PhpTemplateEngine([templateDir('<?= $e($invoice->number) ?>')]))->render('custom', $view);

    (new MandatoryFieldGuard)->check(MandatoryFieldGuard::baseFields($invoice), $view->accessLog());
})->throws(MandatoryFieldMissing::class, 'Amount due (BT-115)');

it('reports missing values without exception in log mode', function () {
    $view = viewModel();
    $missing = (new MandatoryFieldGuard(MandatoryFieldGuard::MODE_LOG))->check(new MandatoryFieldList([new MandatoryField('seller.vatId', 'VAT ID')]), $view->accessLog());

    expect($missing)->toHaveCount(1)
        ->and($missing[0]->accessKey)->toBe('seller.vatId');
});

it('accepts alternatives and can be switched off', function () {
    $view = viewModel();
    $view->seller->displayName;
    $fields = new MandatoryFieldList([new MandatoryField('seller.name|seller.displayName', 'Seller name')]);

    expect((new MandatoryFieldGuard)->check($fields, $view->accessLog()))->toBe([])
        ->and((new MandatoryFieldGuard(MandatoryFieldGuard::MODE_OFF))->check(new MandatoryFieldList([new MandatoryField('nothing', 'x')]), $view->accessLog()))->toBe([]);
});

it('escapes values in plain PHP templates', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->number('<b>X</b>')->build());
    $view = (new ViewModelFactory)->build($invoice);

    expect((new PhpTemplateEngine([templateDir('<?= $e($invoice->number) ?>')]))->render('custom', $view))->toBe('&lt;b&gt;X&lt;/b&gt;');
});

it('is read-only', function () {
    $view = viewModel();
    $view['number'] = 'changed';
})->throws(LogicException::class);

it('scales large logos once and caches them', function () {
    $dir = sys_get_temp_dir().'/quittio-logo-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $image = imagecreatetruecolor(2000, 500);
    imagepng($image, $dir.'/logo.png');
    $cache = new Quittio\Core\Pdf\LogoCache($dir.'/cache', 600, 200);

    $first = $cache->resolve($dir.'/logo.png');
    $second = $cache->resolve($dir.'/logo.png');

    expect($first)->not->toBe($dir.'/logo.png')
        ->and($second)->toBe($first)
        ->and(array_slice((array) getimagesize((string) $first), 0, 2))->toBe([600, 150])
        ->and($cache->resolve('data:image/png;base64,AAA'))->toBeNull()
        ->and($cache->resolve(null))->toBeNull();
})->skip(! extension_loaded('gd'), 'GD is not available.');

it('asks only for payment on invoices, not on credit notes', function () {
    $credit = (new En16931Calculator)->calculate(Invoices::simple()->type('381')->paymentTerms(fn ($t) => $t->dueInDays(14)->cashDiscount(7, '2'))->build());
    $view = (new ViewModelFactory)->build($credit, new ViewContext(locale: 'en'));
    $terms = implode(' ', $view->paymentTerms->lines->toArray());

    // The discount agreement is stated as in BT-20, but without a date or an amount to pay.
    expect($view->payment->qrCode)->toBeNull()
        ->and($view->payment->isRefund)->toBeTrue()
        ->and($view->totals->amountDueLabel)->toBe('Amount to be refunded')
        ->and($terms)->not->toContain('Please pay')
        ->and($terms)->toContain('For payment within 7 days we grant a cash discount of 2%.')
        ->and($terms)->not->toContain('amount')
        ->and((new Quittio\Core\Pdf\Qr\EpcGiroCodeGenerator)->supports($credit))->toBeFalse()
        ->and(viewModel()->payment->qrCode)->not->toBeNull()
        ->and(viewModel()->payment->isRefund)->toBeFalse()
        ->and(viewModel()->totals->amountDueLabel)->toBe('Amount due');
});

it('records only printed leaf values, not counting or iterating', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->note('Reverse charge', 'REG')->build());
    $counted = (new ViewModelFactory)->build($invoice);
    (new PhpTemplateEngine([templateDir('<?php if (count($invoice->legalNotes) > 0 && ! $invoice->notes->isEmpty()): ?>x<?php endif ?><?php foreach ($invoice->lineGroups as $group): endforeach ?>')]))->render('custom', $counted);
    $printed = (new ViewModelFactory)->build($invoice);
    (new PhpTemplateEngine([templateDir('<?php foreach ($invoice->legalNotes as $note): ?><?= $e($note) ?><?php endforeach ?><?php foreach ($invoice->lineGroups as $g): foreach ($g->lines as $l): ?><?= $e($l->period) ?><?php endforeach; endforeach ?>')]))->render('custom', $printed);

    // The lines have no period: reading null values is not recorded.
    expect($counted->accessLog()->accessed('legalNotes'))->toBeFalse()
        ->and($counted->accessLog()->accessed('lineGroups'))->toBeFalse()
        ->and($printed->accessLog()->accessed('legalNotes'))->toBeTrue()
        ->and($printed->accessLog()->accessed('legalNotes[*]'))->toBeTrue()
        ->and($printed->accessLog()->accessed('lineGroups[*].lines[*].period'))->toBeFalse()
        ->and($printed->accessLog()->accessed('lineGroups[*].lines[*].name'))->toBeFalse()
        ->and($printed->accessLog()->accessed('lines[*].period'))->toBeFalse();
});

it('requires regulatory notes and the tax representative in the PDF', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()
        ->note('Gutschrift', 'REG')
        ->taxRepresentative('Fiskal GmbH', 'DE111111111', Quittio\Core\Data\Party\Address::make('Weg 2', '10115', 'Berlin', 'DE'))
        ->build());
    $fields = array_map(static fn (MandatoryField $f): string => $f->accessKey, MandatoryFieldGuard::baseFields($invoice)->fields);

    $view = (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de'));
    $html = (new PhpTemplateEngine)->render('default', $view);

    expect($fields)->toContain('legalNotes[*]')
        ->and($fields)->toContain('taxRepresentative.vatId')
        ->and((new MandatoryFieldGuard)->check(MandatoryFieldGuard::baseFields($invoice), $view->accessLog()))->toBe([])
        ->and($html)->toContain('Steuerlicher Vertreter')
        ->and($html)->toContain('DE111111111')
        ->and($html)->toContain('Gutschrift');

    $custom = (new ViewModelFactory)->build($invoice);
    (new PhpTemplateEngine([templateDir('<?php foreach ($invoice->notes as $note): ?><?= $note->html ?><?php endforeach ?>')]))->render('custom', $custom);
    $missing = (new MandatoryFieldGuard(MandatoryFieldGuard::MODE_LOG))->check(new MandatoryFieldList([new MandatoryField('legalNotes', 'Notes'), new MandatoryField('taxRepresentative.vatId', 'Representative')]), $custom->accessLog());

    expect($missing)->toHaveCount(2);
});

it('states the amount after each cash discount tier', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->paymentTerms(fn ($t) => $t->dueInDays(30)->cashDiscount(7, '3')->cashDiscount(14, '2'))->build());
    $view = (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de'));
    $discounts = $view->paymentTerms->discounts;

    expect(spaces((string) $discounts[0]->amount))->toBe('1.154,30 €')
        ->and(spaces((string) $discounts[1]->amount))->toBe('1.166,20 €')
        ->and((string) $discounts[1]->until)->toBe('17.10.2026')
        ->and(spaces($discounts[1]->text))->toBe('2 % Skonto bis 17.10.2026: 1.166,20 €');
});

it('titles every type code by its family', function (string $code, string $title) {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->type($code)->build());

    expect((new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de'))->title)->toBe($title);
})->with([
    'invoice' => ['380', 'Rechnung'],
    'self-billed invoice' => ['389', 'Gutschrift'],
    'self-billed credit note' => ['261', 'Gutschrift'],
    'self-billed debit note' => ['527', 'Gutschrift'],
    'credit note' => ['381', 'Rechnungskorrektur'],
    'factored credit note' => ['396', 'Rechnungskorrektur'],
    'credit note goods' => ['81', 'Rechnungskorrektur'],
    'forwarder credit note' => ['532', 'Rechnungskorrektur'],
    'proforma' => ['325', 'Proforma-Rechnung'],
    'prepayment' => ['386', 'Anzahlungsrechnung'],
    'debit note' => ['383', 'Rechnung'],
]);

it('shows the price base quantity and gross prices next to the net price', function () {
    $net = (new En16931Calculator)->calculate(Invoices::base()
        ->line(fn ($l) => $l->name('Screws')->quantity(250, 'H87')->netPrice('12.3456', '100')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('Nuts')->quantity(3, 'H87')->netPrice('2.00')->vat(VatCategory::StandardRate, '19'))
        ->build());
    $view = (new ViewModelFactory)->build($net, new ViewContext(locale: 'de'));
    $gross = (new En16931Calculator)->calculate(Invoices::base()
        ->line(fn ($l) => $l->name('Shirt')->quantity(3, 'H87')->priceInclVat('9.99')->vat(VatCategory::StandardRate, '19'))
        ->build());
    // The unit price column stays net, like the line total next to it; the gross price is added below.
    $grossHtml = spaces((new PhpTemplateEngine)->render('default', (new ViewModelFactory)->build($gross, new ViewContext(locale: 'de', defaults: new Presentation(showGrossPrices: true)))));

    expect((string) $view->lines[0]->unitPriceBase)->toBe('100 Stk.')
        ->and($view->lines[1]->unitPriceBase)->toBeNull()
        ->and(spaces((new PhpTemplateEngine)->render('default', $view)))->toContain('12,3456 €<div class="description">/ 100 Stk.</div>')
        ->and($grossHtml)->toContain('8,393 €<div class="description">Einzelpreis brutto 9,99 €</div>');
});

it('names reverse charge, zero rate and non-taxable rows correctly', function (VatCategory $category, ?string $rate, string $label) {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()
        ->line(fn ($l) => $l->name('Other')->quantity(1, 'H87')->netPrice('50.00')->vat($category, $rate, 'Reason', null))
        ->build());
    $labels = array_map(static fn ($row): string => spaces((string) $row->label), iterator_to_array((new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de'))->vatRows));

    expect($labels)->toContain($label);
})->with([
    'reverse charge' => [VatCategory::ReverseCharge, '0', 'Keine USt. (Steuerschuldnerschaft des Leistungsempfängers) auf 50,00 €'],
    'zero rate' => [VatCategory::ZeroRated, '0', '0 % USt. (Nullsatz) auf 50,00 €'],
    'exempt' => [VatCategory::Exempt, '0', 'Umsatzsteuerfrei (Steuerbefreit) auf 50,00 €'],
]);

it('names the VAT rate of split document allowances', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()
        ->line(fn ($l) => $l->name('Book')->quantity(1, 'H87')->netPrice('20.00')->vat(VatCategory::StandardRate, '7'))
        ->discountPercent('10', 'Project')
        ->build());
    $labels = array_map(static fn ($a): string => spaces((string) $a->label), iterator_to_array((new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de'))->allowances));

    expect($labels)->toBe(['Nachlass Project 10 % (19 % USt.)', 'Nachlass Project 10 % (7 % USt.)']);
});

it('labels a single delivery date and shows it next to an invoicing period', function () {
    $date = (new ViewModelFactory)->build((new En16931Calculator)->calculate(Invoices::simple()->build()), new ViewContext(locale: 'de'));
    $period = (new ViewModelFactory)->build((new En16931Calculator)->calculate(Invoices::simple()->invoicingPeriod('2026-09-01', '2026-09-30')->build()), new ViewContext(locale: 'de'));

    expect($date->servicePeriodLabel)->toBe('Leistungsdatum')
        ->and($date->deliveryDate)->toBeNull()
        ->and($period->servicePeriodLabel)->toBe('Leistungszeitraum')
        ->and((string) $period->deliveryDate)->toBe('30.09.2026')
        ->and((new PhpTemplateEngine)->render('default', $period))->toContain('30.09.2026');
});

it('uses the locale of the render call before the locale of the invoice', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->locale('de')->build());

    expect((new ViewModelFactory)->build($invoice, new ViewContext(locale: 'en'))->title)->toBe('Invoice')
        ->and((new ViewModelFactory)->build($invoice)->title)->toBe('Rechnung');
});

it('shows payee and delivery address', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()
        ->payee('Factor Bank AG')
        ->deliverTo('Warehouse', Quittio\Core\Data\Party\Address::make('Dock 7', '20457', 'Hamburg', 'DE'))
        ->build());
    $html = (new PhpTemplateEngine)->render('default', (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'en')));

    expect($html)->toContain('Payee: Factor Bank AG')
        ->and($html)->toContain('Deliver to')
        ->and($html)->toContain('Dock 7');
});

it('notes the discounted amount and deadline of the payment QR code', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->paymentTerms(fn ($t) => $t->dueInDays(30)->cashDiscount(10, '2'))->build());
    $view = (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'en', options: ['girocode_amount' => 'discounted']));

    expect($view->payment->qrCode->payload)->toContain('EUR1166.20')
        ->and(spaces((string) $view->payment->qrCode->note))->toBe('QR code with the amount after cash discount (1,166.20 €), valid until 13/10/2026.')
        ->and(viewModel()->payment->qrCode->note)->toBeNull();
});

it('shows the watermark in HTML with an escaped CSS overlay', function () {
    $view = viewModel('de', new Presentation(watermark: 'ENTWURF </style><b>'));
    $html = (new PhpTemplateEngine)->render('default', $view);

    expect($html)->toContain('body::before { content: "ENTWURF \\3C \\2F style\\3E \\3C b\\3E ";')
        ->and($html)->not->toContain('</style><b>')
        ->and(viewModel()->design->watermarkCss)->toBeNull();
});

it('places the address field at the position of the letter layout', function () {
    $layout = new Quittio\Core\View\LetterLayout('test', addressTop: 27.0, addressLeft: 25.0);
    $country = new class($layout) extends Quittio\Core\Countries\AbstractCountryPack
    {
        public function __construct(private readonly Quittio\Core\View\LetterLayout $layout) {}

        public function code(): string
        {
            return 'XX';
        }

        public function letterLayouts(): array
        {
            return ['test' => $this->layout];
        }
    };
    $invoice = (new En16931Calculator)->calculate(Invoices::simple()->build());
    $view = (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'en', country: $country, defaults: new Presentation(letterLayout: 'test')));

    expect((new PhpTemplateEngine)->render('default', $view))->toContain('.address { position: absolute; top: 27mm; left: 25mm;');
});

it('shows the gross price per line with LineBuilder::showGrossPrice(), over the presentation', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::base()
        ->line(fn ($l) => $l->name('Shirt')->quantity(3, 'H87')->priceInclVat('9.99')->vat(VatCategory::StandardRate, '19')->showGrossPrice())
        ->line(fn ($l) => $l->name('Cap')->quantity(1, 'H87')->priceInclVat('5.95')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('Socks')->quantity(2, 'H87')->priceInclVat('3.50')->vat(VatCategory::StandardRate, '19')->showGrossPrice(false))
        ->build());
    $plain = (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de'));
    $gross = (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de', defaults: new Presentation(showGrossPrices: true)));
    $html = spaces((new PhpTemplateEngine)->render('default', $plain));

    expect($invoice->lines[0]->presentation?->showGrossPrice)->toBeTrue()
        ->and([$plain->lines[0]->showGrossPrice, $plain->lines[1]->showGrossPrice, $plain->lines[2]->showGrossPrice])->toBe([true, false, false])
        ->and([$gross->lines[0]->showGrossPrice, $gross->lines[1]->showGrossPrice, $gross->lines[2]->showGrossPrice])->toBe([true, true, false])
        ->and($html)->toContain('Einzelpreis brutto 9,99 €')
        ->and($html)->not->toContain('Einzelpreis brutto 5,95 €');
});

it('highlights lines and uses the secondary colour in the default template', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::base()
        ->line(fn ($l) => $l->name('Consulting')->quantity(10, 'HUR')->netPrice('100.00')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('Express surcharge')->quantity(1, 'C62')->netPrice('50.00')->vat(VatCategory::StandardRate, '19')->highlight())
        ->build());
    $render = static fn (?Presentation $presentation): string => (new PhpTemplateEngine)->render('default', (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'en', defaults: $presentation)));
    $html = $render(new Presentation(colors: new Colors(primary: '#1e40af', secondary: '#0f766e')));

    expect(substr_count($html, '<tr class="line highlight"'))->toBe(1)
        ->and($html)->toMatch('#<tr class="line highlight"[^>]*>\s*<td class="position">2</td>#')
        ->and($html)->toContain('.lines .highlight td.position { border-left: 0.8mm solid #0f766e;')
        ->and($html)->toContain('.section-title { font-size: 9pt; color: #0f766e;')
        ->and($html)->toContain('h1 { font-size: 13.5pt; color: #1e40af;')
        // Without a secondary colour the primary colour is used.
        ->and($render(null))->toContain('.section-title { font-size: 9pt; color: #2741d9;');
});

it('prints the issuer tax identifiers in the footer and counts them as printed', function () {
    $invoice = (new En16931Calculator)->calculate(Scenarios::make('simple')->build());
    $view = (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de'));
    $html = (new PhpTemplateEngine)->render('default', $view);
    $custom = (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de', defaults: new Presentation(footerColumns: [['Muster GmbH']])));
    $customHtml = (new PhpTemplateEngine)->render('default', $custom);

    expect($view->design->footerColumns->toArray())->toBe([
        ['Muster GmbH', 'Hauptstraße 1', '12345 Musterstadt'],
        ['+49 123 456789', 'rechnung@muster.de'],
        ['HRB 12345', 'Amtsgericht Musterstadt', 'Geschäftsführer: Max Muster'],
        ['USt-IdNr. DE123456789', 'St.-Nr. 12/345/67890'],
    ])
        ->and($view->design->footerHasTaxIds)->toBeTrue()
        ->and($view->accessLog()->accessed('seller.vatId'))->toBeTrue()
        ->and($view->accessLog()->accessed('seller.taxNumber'))->toBeTrue()
        // Not twice: the invoice details show the buyer's identifier only.
        ->and($html)->not->toContain('<td class="label">USt-IdNr.</td>')
        ->and($html)->toContain('<td class="label">Ihre USt-IdNr.</td><td class="value">DE987654321</td>')
        // A footer of its own: the seller's identifiers move to the invoice details.
        ->and($custom->design->footerHasTaxIds)->toBeFalse()
        ->and($customHtml)->toContain('<td class="label">USt-IdNr.</td><td class="value">DE123456789</td>')
        ->and($customHtml)->toContain('<td class="label">Steuernummer</td><td class="value">12/345/67890</td>');
});

it('names a single VAT rate without its base, several with it', function () {
    $view = static fn (string $scenario) => (new ViewModelFactory)->build((new En16931Calculator)->calculate(Scenarios::make($scenario)->build()), new ViewContext(locale: 'de'));

    expect(spaces($view('simple')->vatRows[0]->label))->toBe('USt. 19 %')
        ->and(spaces($view('mixed-vat')->vatRows[0]->label))->toContain(' auf ');
});

it('prints the amount due once when it is the gross total', function () {
    $simple = (new ViewModelFactory)->build((new En16931Calculator)->calculate(Scenarios::make('simple')->build()), new ViewContext(locale: 'de'));
    $rounded = (new ViewModelFactory)->build((new En16931Calculator)->calculate(Scenarios::make('b2c-gross')->build()), new ViewContext(locale: 'de'));
    $html = (new PhpTemplateEngine)->render('default', $simple);

    expect($simple->totals->dueEqualsGross)->toBeTrue()
        ->and($rounded->totals->dueEqualsGross)->toBeFalse()
        ->and(spaces($html))->toContain('<tr class="grand"><td class="grand-label">Gesamtbetrag</td><td class="right amount">2.747,71 €</td></tr>')
        ->and($html)->not->toContain('Zahlbetrag')
        ->and($simple->accessLog()->accessed('totals.amountDue'))->toBeTrue();
});

it('shortens a service period within one year', function () {
    $period = static function (string $start, string $end, string $locale): string {
        $invoice = (new En16931Calculator)->calculate(Invoices::base()->line(fn ($l) => $l->name('Hosting')->quantity(1, 'MON')->netPrice('29.00')->vat(VatCategory::StandardRate, '19')->period($start, $end))->build());

        return (string) (new ViewModelFactory)->build($invoice, new ViewContext(locale: $locale))->lines[0]->period;
    };

    expect($period('2026-10-01', '2026-10-31', 'de'))->toBe('01.10.–31.10.2026')
        ->and($period('2026-12-15', '2027-01-15', 'de'))->toBe('15.12.2026–15.01.2027')
        ->and($period('2026-10-01', '2026-10-31', 'en'))->toBe('01/10/2026–31/10/2026')
        ->and($period('2026-10-01', '2026-10-01', 'de'))->toBe('01.10.2026');
});

it('asks for a transfer only when the buyer pays by transfer', function () {
    $terms = static fn ($builder) => (new ViewModelFactory)->build((new En16931Calculator)->calculate($builder->build()), new ViewContext(locale: 'de'))->paymentTerms->lines[0];

    expect($terms(Scenarios::make('simple')))->toBe('Bitte bis zum 17.10.2026 überweisen.')
        ->and($terms(Scenarios::make('simple')->payment(PaymentMeansCode::SepaDirectDebit)->directDebit('M-1', 'DE98ZZZ09999999999', 'DE02120300000000202051')))->toBe('Bitte zahlen Sie den Betrag bis zum 17.10.2026.');
});

it('sums the line amounts up to each line in display order for the carry-over', function () {
    $invoice = (new En16931Calculator)->calculate(Invoices::base()
        ->group('g1', 'Group 1')
        ->line(fn ($l) => $l->name('A')->quantity(1, 'C62')->netPrice('10.00')->vat(VatCategory::StandardRate, '19'))
        ->line(fn ($l) => $l->name('B')->quantity(2, 'C62')->netPrice('20.00')->vat(VatCategory::StandardRate, '19')->group('g1'))
        ->line(fn ($l) => $l->name('C')->quantity(1, 'C62')->netPrice('5.50')->vat(VatCategory::StandardRate, '19'))
        ->build());
    $view = (new ViewModelFactory)->build($invoice, new ViewContext(locale: 'de'));
    $carries = [];
    foreach ($view->lineGroups as $group) {
        foreach ($group->lines as $line) {
            $carries[$line->name] = spaces((string) $line->carryOver);
        }
    }

    // The group comes first: B, then the ungrouped lines A and C.
    expect($carries)->toBe(['B' => '40,00 €', 'A' => '50,00 €', 'C' => '55,50 €']);
});

it('groups French amounts with a no-break space every font has', function () {
    $amount = (new IntlFormatter)->money(Money::of('1234.50', 'EUR'), 'fr');

    expect($amount)->not->toContain("\u{202F}")
        ->and(spaces($amount))->toBe('1 234,50 €');
})->skip(fn () => ! extension_loaded('intl'), 'intl is not available.');
