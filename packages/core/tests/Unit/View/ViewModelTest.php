<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Countries\MandatoryFieldList;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Templates\PhpTemplateEngine;
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
        ->and($cache->resolve('data:image/png;base64,AAA'))->toBe('data:image/png;base64,AAA')
        ->and($cache->resolve(null))->toBeNull();
})->skip(! extension_loaded('gd'), 'GD is not available.');
