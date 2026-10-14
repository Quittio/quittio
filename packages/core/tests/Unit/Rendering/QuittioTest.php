<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Config;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Quittio;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\Plausibility\VatIdVerification;
use Quittio\Core\Validation\Plausibility\VatIdVerifier;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationDriver;
use Quittio\Core\Validation\ValidationException;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;

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

it('prepares an invoice like a render run without rendering', function () {
    $prepared = quittio()->make(Invoices::simple()->build())->format('zugferd')->prepare();

    expect($prepared->data->isCalculated())->toBeTrue()
        ->and($prepared->format())->toBe('zugferd')
        ->and($prepared->profile()?->key())->toBe('en16931')
        ->and($prepared->country->code())->toBe('DE')
        ->and($prepared->locale)->toBe('en')
        ->and($prepared->validation->isValid())->toBeTrue()
        ->and($prepared->validation->levels)->toContain(ValidationLevel::L4);
});

it('prepares formats without XML without a profile', function () {
    $prepared = quittio()->make(Invoices::simple()->build())->format('pdf')->prepare();

    expect($prepared->formats)->toBe(['pdf' => null])
        ->and($prepared->profile())->toBeNull();
});

it('renders watermarked invoices as plain PDF without XML', function () {
    $pdf = quittio()->make(Invoices::simple()->presentation(new Presentation(watermark: 'ENTWURF')))->format('pdf')->withoutValidation()->render();
    $embedding = quittio(['pdf' => ['b2c_embed_xml' => true]])->make(Invoices::simple()->presentation(new Presentation(watermark: 'ENTWURF')))->format('pdf')->render();

    expect($pdf->pdf())->not->toContain('pdfaid:part')
        ->and($embedding->hasXml())->toBeFalse()
        ->and($embedding->profile())->toBeNull()
        ->and($embedding->pdf())->not->toContain('pdfaid:part')
        ->and((new PdfEmbeddedFileExtractor)->extract($embedding->pdf()))->toBeNull()
        ->and(quittio(['pdf' => ['b2c_embed_xml' => true]])->make(Invoices::simple())->format('pdf')->render()->hasXml())->toBeTrue();
});

it('refuses to embed the e-invoice XML into a watermarked draft', function () {
    quittio()->make(Invoices::simple()->presentation(new Presentation(watermark: 'ENTWURF')))->format('zugferd')->render();
})->throws(ConfigurationException::class, 'cannot be rendered with a watermark');

it('renders watermarked documents without PDF/A, so that the watermark shows', function () {
    $document = DocumentBuilder::quote()->number('Q-1')->issueDate('2026-10-01')->currency('EUR')
        ->seller(fn ($s) => $s->name('Seller Ltd')->address('Main Street 1', '12345', 'Sample City', 'DE'))
        ->buyer(fn ($b) => $b->name('Buyer Inc')->address('Station Road 5', '54321', 'Example Town', 'DE'))
        ->line(fn ($l) => $l->name('Consulting')->quantity(1, 'HUR')->netPrice('100.00')->vat(VatCategory::StandardRate, '19'));

    expect(quittio()->document($document->build())->render()->pdf())->toContain('pdfaid:part')
        ->and(quittio()->document($document->presentation(new Presentation(watermark: 'ENTWURF'))->build())->render()->pdf())->not->toContain('pdfaid:part');
});

it('takes the paper size from the presentation, the configuration or the country pack', function () {
    $mediaBox = static function (string $pdf): string {
        preg_match('#/MediaBox\s*\[([^\]]+)\]#', $pdf, $m);

        return trim((string) preg_replace('/\s+/', ' ', $m[1] ?? ''));
    };

    $a4 = quittio()->make(Invoices::simple())->format('pdf')->render()->pdf();
    $letter = quittio()->make(Invoices::simple()->presentation(new Presentation(paper: 'Letter')))->format('pdf')->render()->pdf();
    $configured = quittio(['presentation' => new Presentation(paper: 'A5')])->make(Invoices::simple())->format('pdf')->render()->pdf();

    expect($mediaBox($a4))->toBe('0 0 595.280 841.890')
        ->and($mediaBox($letter))->toBe('0 0 612.000 792.000')
        ->and($mediaBox($configured))->toBe('0 0 419.530 595.280');
});

it('keeps the VAT identifier verifier when the rules run for formats without XML', function () {
    $verifier = new class implements VatIdVerifier
    {
        /** @var list<string> */
        public array $checked = [];

        public function verify(string $vatId): VatIdVerification
        {
            $this->checked[] = $vatId;

            return new VatIdVerification($vatId, false);
        }
    };
    $quittio = quittio()->withoutValidators()->withValidator(new PhpRulesDriver(vatIdVerifier: $verifier));
    $result = $quittio->make(Invoices::simple())->format('pdf')->validate(throwOnError: false)->render();

    expect($verifier->checked)->not->toBeEmpty()
        ->and($result->validation()->has('QUITTIO-VIES'))->toBeTrue();
});

it('names XML-only files by the title of the type code, never by a raw label key', function () {
    expect(quittio()->make(Invoices::simple()->type('383'))->format('ubl')->render()->filename())->toBe('Invoice_INV-2026-0001.xml')
        ->and(quittio()->make(Invoices::simple()->type('396'))->format('ubl')->withoutValidation()->render()->filename())->toBe('Credit_note_INV-2026-0001.xml')
        ->and(quittio()->make(Invoices::simple()->presentation(new Presentation(title: 'Storno')))->format('cii')->render()->filename())->toBe('Storno_INV-2026-0001.xml');
});

it('validates in check() and prepare() whatever the validation mode', function () {
    $invalid = Invoices::simple()->number('');

    expect(quittio(['validation' => ['mode' => 'off']])->make($invalid)->format('cii')->check()->has('BR-02'))->toBeTrue()
        ->and(quittio()->make($invalid)->format('cii')->withoutValidation()->check()->has('BR-02'))->toBeTrue()
        ->and(quittio()->make($invalid)->format('cii')->withoutValidation()->prepare()->validation->isValid())->toBeFalse()
        ->and(quittio()->make($invalid)->format('cii')->validate()->check()->isValid())->toBeFalse();
});

it('checks and prepares the XML that pdf embeds with b2c_embed_xml, exactly as render() writes it', function () {
    $recorder = new class implements ValidationDriver
    {
        /** @var list<array{?Syntax, ?string}> */
        public array $seen = [];

        public function name(): string
        {
            return 'recorder';
        }

        public function levels(): array
        {
            return [ValidationLevel::L5];
        }

        public function supports(ValidationSubject $subject): bool
        {
            return $subject->xml !== null;
        }

        public function validate(ValidationSubject $subject): ValidationResult
        {
            $this->seen[] = [$subject->syntax, $subject->xml];

            return new ValidationResult([new ValidationMessage(Severity::Info, 'TEST-XML', 'XML checked', ValidationLevel::L5)], [ValidationLevel::L5]);
        }
    };
    $quittio = quittio(['pdf' => ['b2c_embed_xml' => true]])->withValidator($recorder);
    $consumer = buyerIn('DE', consumer: true);

    $prepared = $quittio->make($consumer)->format('pdf')->prepare();
    $checked = $quittio->make($consumer)->format('pdf')->check();
    $rendered = $quittio->make($consumer)->format('pdf')->render();

    expect($prepared->formats)->toBe(['pdf' => $prepared->profile()])
        ->and($prepared->profile()?->key())->toBe($rendered->profile()?->key())
        ->and($prepared->validation->has('TEST-XML'))->toBeTrue()
        ->and($prepared->validation->levels)->toContain(ValidationLevel::L4)
        ->and($checked->has('TEST-XML'))->toBeTrue()
        ->and($recorder->seen)->toHaveCount(3)
        ->and($recorder->seen[0])->toBe([Syntax::Cii, $rendered->xml()])
        ->and($recorder->seen[1])->toBe([Syntax::Cii, $rendered->xml()]);

    // Watermarked runs and formats without PDF embed no XML, as in render().
    $draft = $quittio->make(buyerIn('DE', consumer: true)->presentation(new Presentation(watermark: 'ENTWURF')))->format('pdf')->prepare();
    $html = $quittio->make($consumer)->format('html')->prepare();

    expect($draft->profile())->toBeNull()
        ->and($draft->validation->has('TEST-XML'))->toBeFalse()
        ->and($html->formats)->toBe(['html' => null])
        ->and($recorder->seen)->toHaveCount(3);
});

it('refuses e-invoice XML for watermarked data in check() and prepare() as in render()', function () {
    quittio()->make(Invoices::simple()->presentation(new Presentation(watermark: 'ENTWURF')))->format('zugferd')->check();
})->throws(ConfigurationException::class, 'cannot be rendered with a watermark');

it('reads every documented key of the core configuration array', function () {
    $config = Config::fromArray([
        'format' => 'zugferd', 'profile' => 'basic', 'locale' => 'fr', 'country' => 'AT', 'due_days' => 30,
        'template' => 'custom', 'template_paths' => ['/templates'], 'filename' => '{number}.{ext}',
        'pdf' => ['pdfa' => false, 'b2c_embed_xml' => true, 'girocode_amount' => 'discounted'],
        'auto_upgrade_profile' => false,
        'validation' => ['mode' => 'off', 'levels' => ['L1'], 'block_on_warnings' => true, 'verapdf' => ['enabled' => true, 'mode' => 'cli']],
        'mandatory_fields' => 'log', 'temp_dir' => '/tmp/quittio', 'options' => ['fold_marks' => true],
        'presentation' => new Presentation(paper: 'Letter'),
        'reader' => ['max_xml_bytes' => 1, 'max_pdf_bytes' => 2, 'max_decompressed_bytes' => 3],
        'corrections' => ['cancellation_strategy' => 'negative_invoice'],
    ]);

    expect([$config->format, $config->profile, $config->locale, $config->country, $config->dueDays])->toBe(['zugferd', 'basic', 'fr', 'AT', 30])
        ->and([$config->template, $config->templatePaths, $config->filename])->toBe(['custom', ['/templates'], '{number}.{ext}'])
        ->and([$config->pdfa, $config->b2cEmbedXml, $config->girocodeAmount, $config->autoUpgradeProfile])->toBe([false, true, 'discounted', false])
        ->and([$config->validation, $config->validationLevels, $config->blockOnWarnings, $config->veraPdf])->toBe([Config::VALIDATION_OFF, [ValidationLevel::L1], true, ['mode' => 'cli']])
        ->and([$config->mandatoryFields, $config->tempDir, $config->options, $config->presentation?->paper])->toBe(['log', '/tmp/quittio', ['fold_marks' => true], 'Letter'])
        ->and([$config->maxXmlBytes, $config->maxPdfBytes, $config->maxDecompressedBytes, $config->cancellationStrategy])->toBe([1, 2, 3, 'negative_invoice']);
});
