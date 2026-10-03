<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Config;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Quittio;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Validation\ValidationException;
use Quittio\Core\Validation\ValidationLevel;

function quittio(array $config = []): Quittio
{
    return Quittio::create($config);
}

function buyerIn(string $country, bool $consumer = false): InvoiceBuilder
{
    return Invoices::simple()->buyer(fn ($b) => $b->name('Buyer')->address('Street 1', '1000', 'City', $country)->consumer($consumer));
}

function invoiceWithSubLines(): InvoiceBuilder
{
    return Invoices::base()->line(fn ($l) => $l->name('Set')->vat(VatCategory::StandardRate, '19')
        ->subLine(fn ($s) => $s->name('Part')->quantity(2, 'H87')->netPrice('50.00')->vat(VatCategory::StandardRate, '19')));
}

it('renders ZUGFeRD with the XML embedded byte for byte', function () {
    $result = quittio()->make(Invoices::simple()->build())->format('zugferd')->render();

    expect($result->format())->toBe('zugferd')
        ->and($result->profile()?->key())->toBe('en16931')
        ->and($result->hasPdf())->toBeTrue()
        ->and((new PdfEmbeddedFileExtractor)->extract($result->pdf())[1] ?? null)->toBe($result->xml())
        ->and($result->validation()->isValid())->toBeTrue()
        ->and($result->validation()->levels)->toContain(ValidationLevel::L4)
        ->and($result->filename())->toBe('Invoice_INV-2026-0001.pdf')
        ->and($result->filename('xml'))->toBe('Invoice_INV-2026-0001.xml')
        ->and($result->hashes())->toHaveKeys(['pdf', 'xml'])
        ->and($result->mimeType())->toBe('application/pdf');
});

it('produces several formats from one calculation', function () {
    $result = quittio()->make(Invoices::simple())->formats(['zugferd', 'ubl', 'json', 'html'])->render();

    expect($result->formats())->toBe(['zugferd', 'ubl', 'json', 'html'])
        ->and($result->as('ubl')->xml())->toContain('<Invoice ')
        ->and($result->as('ubl')->hasPdf())->toBeFalse()
        ->and(json_decode($result->as('json')->json(), true)['number'] ?? null)->toBe('INV-2026-0001')
        ->and($result->as('html')->html())->toContain('INV-2026-0001')
        ->and($result->as('ubl')->as('zugferd'))->toBe($result);
});

it('writes deterministic XML', function () {
    $quittio = quittio();
    $first = $quittio->make(Invoices::simple()->build())->format('cii')->render()->xml();
    $second = $quittio->make(Invoices::simple()->build())->format('cii')->render()->xml();

    expect(hash('sha256', $first))->toBe(hash('sha256', $second));
});

it('chooses the format automatically', function (Closure $invoice, string $format, ?string $profile) {
    $result = quittio()->make($invoice())->render();

    expect($result->format())->toBe($format)
        ->and($result->profile()?->key())->toBe($profile);
})->with([
    'domestic B2B' => [fn () => Invoices::simple(), 'zugferd', 'en16931'],
    'EU cross-border' => [fn () => buyerIn('FR'), 'zugferd', 'en16931'],
    'consumer' => [fn () => buyerIn('DE', consumer: true), 'pdf', null],
    'outside the EU' => [fn () => buyerIn('US'), 'pdf', null],
]);

it('upgrades to EXTENDED for sub lines', function () {
    $invoice = invoiceWithSubLines();

    expect(quittio()->make($invoice)->format('zugferd')->render()->profile()?->key())->toBe('extended');
});

it('does not upgrade without permission', function () {
    $invoice = invoiceWithSubLines();

    quittio(['auto_upgrade_profile' => false])->make($invoice)->format('zugferd')->render();
})->throws(ConfigurationException::class, 'EXTENDED');

it('throws on validation errors and attaches them in warn mode', function () {
    $invalid = Invoices::simple()->number('');

    expect(fn () => quittio()->make($invalid)->format('cii')->render())->toThrow(ValidationException::class);

    $result = quittio()->make($invalid)->format('cii')->validate(throwOnError: false)->render();
    expect($result->validation()->isValid())->toBeFalse()
        ->and($result->validation()->has('BR-02'))->toBeTrue();
});

it('skips validation on request', function () {
    expect(quittio()->make(Invoices::simple()->number(''))->format('cii')->withoutValidation()->render()->validation()->messages)->toBe([]);
});

it('rejects unknown formats and unsupported profiles', function (string $format, string $profile) {
    quittio()->make(Invoices::simple())->format($format)->profile($profile)->render();
})->with([
    ['unknown', 'en16931'],
    ['zugferd', 'peppol'],
    ['peppol', 'en16931'],
])->throws(ConfigurationException::class);

it('validates without rendering', function () {
    $result = quittio()->make(Invoices::simple())->format('ubl')->check();

    expect($result->isValid())->toBeTrue()
        ->and($result->levels)->toContain(ValidationLevel::L4);
});

it('saves to a directory with the configured file name', function () {
    $dir = sys_get_temp_dir().'/quittio-save-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $path = quittio(['filename' => '{number}-{date}.{ext}'])->make(Invoices::simple())->format('cii')->render()->save($dir);

    expect($path)->toBe($dir.'/INV-2026-0001-2026-10-03.xml')
        ->and(file_get_contents($path))->toStartWith('<?xml');
});

it('reads and validates incoming files', function () {
    $quittio = quittio();
    $pdf = $quittio->make(Invoices::simple())->format('zugferd')->render()->pdf();

    expect($quittio->read($pdf)->data->number)->toBe('INV-2026-0001')
        ->and($quittio->validateFile($pdf)->isValid())->toBeTrue();
});

it('renders documents', function () {
    $quote = DocumentBuilder::quote()->number('Q-1')->issueDate('2026-10-01')
        ->seller(fn ($p) => $p->name('Seller Ltd')->address('Main Street 1', '12345', 'Sample City', 'DE'))
        ->buyer(fn ($p) => $p->name('Buyer Inc')->address('Station Road 5', '54321', 'Example Town', 'DE'))
        ->line(fn ($l) => $l->name('Item')->quantity(1, 'H87')->netPrice('10.00')->vat(VatCategory::StandardRate, '19'))
        ->build();
    $document = quittio()->document($quote)->render();

    expect($document->pdf())->toStartWith('%PDF-')
        ->and($document->filename())->toBe('Quote_Q-1.pdf')
        ->and((new PdfEmbeddedFileExtractor)->extract($document->pdf()))->toBeNull();
});

it('reads the configuration from an array', function () {
    $config = Config::fromArray([
        'format' => 'auto',
        'locale' => 'de',
        'pdf' => ['b2c_embed_xml' => true],
        'validation' => ['mode' => 'warn', 'levels' => ['l1', 'L3'], 'kosit' => ['enabled' => true, 'mode' => 'cli', 'jar' => '/x.jar']],
    ]);

    expect($config->format)->toBeNull()
        ->and($config->locale)->toBe('de')
        ->and($config->b2cEmbedXml)->toBeTrue()
        ->and($config->validation)->toBe(Config::VALIDATION_WARN)
        ->and($config->validationLevels)->toBe([ValidationLevel::L1, ValidationLevel::L3])
        ->and($config->kosit)->toBe(['mode' => 'cli', 'jar' => '/x.jar'])
        ->and($config->veraPdf)->toBeNull()
        ->and($config->with(['locale' => 'fr'])->locale)->toBe('fr');
});

it('rejects invalid configuration values', function () {
    Config::fromArray(['validation' => ['mode' => 'sometimes']]);
})->throws(ConfigurationException::class);

it('embeds XML into consumer PDFs on request', function () {
    $result = quittio(['pdf' => ['b2c_embed_xml' => true]])->make(buyerIn('DE', consumer: true))->render();

    expect($result->format())->toBe('pdf')
        ->and((new PdfEmbeddedFileExtractor)->extract($result->pdf())[0] ?? null)->toBe('factur-x.xml');
});
