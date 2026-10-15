<?php

declare(strict_types=1);

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Contracts\ProvidesInvoiceData;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Meta;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Pdf\Renderers\CallbackRenderer;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Templates\PhpTemplateEngine;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\Plausibility\VatIdVerification;
use Quittio\Core\Validation\Plausibility\VatIdVerifier;
use Quittio\Core\Validation\Plausibility\ViesVerifier;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationDriver;
use Quittio\Core\Validation\ValidationException;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Laravel\Events\InvoiceRendered;
use Quittio\Laravel\Events\InvoiceRendering;
use Quittio\Laravel\Events\InvoiceValidated;
use Quittio\Laravel\Events\InvoiceValidationFailed;
use Quittio\Laravel\Events\MandatoryFieldMissing;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Jobs\RenderInvoice;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Rendering\RenderedDocument;
use Quittio\Laravel\Rendering\RenderedInvoice;
use Quittio\Laravel\Seller;

function invoice(): InvoiceData
{
    return Scenarios::make('simple')->build();
}

it('renders ZUGFeRD with the Blade template', function () {
    $result = Quittio::make(invoice())->formats(['zugferd', 'html'])->render();

    expect($result)->toBeInstanceOf(RenderedInvoice::class)
        ->and($result->pdf())->toStartWith('%PDF-')
        ->and((new PdfEmbeddedFileExtractor)->extract($result->pdf())[1] ?? null)->toBe($result->xml())
        ->and($result->filename())->toBe('Rechnung_RE-2026-0042.pdf')
        ->and($result->as('html')->html())->toContain('<h1>Rechnung</h1>')
        ->and($result->as('html')->html())->toContain('Webentwicklung')
        ->and($result->as('html')->html())->toContain('Gesamtbetrag');
});

it('shows the same content as the plain PHP template', function (string $scenario) {
    $invoice = Scenarios::make($scenario)->build();
    $blade = Quittio::make($invoice)->format('html')->validate(false)->render()->html();
    $php = Quittio::core()->withTemplateEngine(new PhpTemplateEngine)->render($invoice, new RenderOptions(formats: ['html'], validation: 'warn'))->html();
    $text = static fn (string $html): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace(['#<style.*?</style>#s', '#<(br|/div|/p|/td)[^>]*>#'], ['', ' '], $html)))));

    expect($text($blade))->toBe($text($php));
})->with(['simple', 'mixed-vat', 'reverse-charge', 'sub-lines', 'cancellation', 'foreign-currency', 'allowances', 'intra-eu', 'b2c-gross', 'attachments']);

it('dispatches rendering events', function () {
    Event::fake();
    Quittio::make(invoice())->format('cii')->render();

    Event::assertDispatched(InvoiceRendering::class);
    Event::assertDispatched(InvoiceValidated::class, fn (InvoiceValidated $e): bool => $e->result->isValid());
    Event::assertDispatched(InvoiceRendered::class, fn (InvoiceRendered $e): bool => $e->result->format() === 'cii');
});

it('dispatches InvoiceValidated only when the run validated', function () {
    Event::fake();
    $invalid = Scenarios::make('simple')->number('')->build();

    Quittio::make($invalid)->format('cii')->validate(false)->render();
    Event::assertDispatched(InvoiceValidated::class, fn (InvoiceValidated $e): bool => ! $e->result->isValid() && $e->result->has('BR-02'));

    Event::fake();
    Quittio::make($invalid)->format('cii')->withoutValidation()->render();
    Event::assertNotDispatched(InvoiceValidated::class);
    Event::assertDispatched(InvoiceRendered::class);

    Event::fake();
    config()->set('quittio.validation.mode', 'off');
    Quittio::flush();
    Quittio::make(invoice())->format('cii')->render();
    Event::assertNotDispatched(InvoiceValidated::class);
    Quittio::make(invoice())->format('cii')->validate()->render();
    Event::assertDispatched(InvoiceValidated::class);
});

it('dispatches an event when validation fails', function () {
    Event::fake();

    expect(fn () => Quittio::make(Scenarios::make('simple')->number('')->build())->format('cii')->render())->toThrow(ValidationException::class);
    Event::assertDispatched(InvoiceValidationFailed::class, fn (InvoiceValidationFailed $e): bool => $e->result->has('BR-02'));
    Event::assertNotDispatched(InvoiceRendered::class);
});

it('reports templates that omit mandatory values', function () {
    Event::fake([MandatoryFieldMissing::class]);
    config()->set('quittio.validation.mandatory_field_guard', 'log');
    view()->addNamespace('broken', __DIR__.'/../Fixtures/views');
    config()->set('quittio.pdf.templates', ['broken::invoice']);
    Quittio::flush();

    $result = Quittio::make(invoice())->format('pdf')->template('broken::invoice')->render();

    expect($result->hasPdf())->toBeTrue();
    Event::assertDispatched(MandatoryFieldMissing::class, fn (MandatoryFieldMissing $e): bool => count($e->fields) > 0);
});

it('returns a download and an inline response', function () {
    $result = Quittio::make(invoice())->format('zugferd')->render();
    $download = $result->download();
    $inline = $result->stream();

    expect($download->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($download->headers->get('Content-Disposition'))->toStartWith('attachment; filename="Rechnung_RE-2026-0042.pdf"')
        ->and($inline->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($result->toResponse(request())->getContent())->toBe($result->pdf());
});

it('transliterates the ASCII fallback file name character by character', function () {
    $result = Quittio::make(invoice())->format('zugferd')->render();
    $disposition = (string) $result->download('Rechnung Über ß 中 😀.pdf')->headers->get('Content-Disposition');

    expect($disposition)->toStartWith('attachment; filename="Rechnung_Uber_ss____.pdf"; filename*=UTF-8\'\'')
        ->and($disposition)->toEndWith(rawurlencode('Rechnung Über ß 中 😀.pdf'))
        ->and($result->stream("Gr\xFC\xDFe.pdf")->headers->get('Content-Disposition'))->toStartWith('inline; filename="Gr__e.pdf"');
});

it('can be attached to mails', function () {
    Mail::fake();
    $result = Quittio::make(invoice())->format('zugferd')->render();

    Mail::to('kunde@example.com')->send(new class($result) extends Mailable
    {
        public function __construct(private RenderedInvoice $invoice) {}

        public function build(): self
        {
            return $this->subject('Rechnung')->html('<p>Anbei</p>')->attach($this->invoice);
        }
    });

    Mail::assertSent(Mailable::class, function (Mailable $mail) use ($result): bool {
        $mail->build();

        return $mail->hasAttachment($result);
    });
});

it('stores outputs on a disk', function () {
    Storage::fake('invoices');
    $result = Quittio::make(invoice())->formats(['zugferd', 'xrechnung-cii'])->render();

    $pdf = $result->save('invoices', '2026/');
    $xml = $result->as('xrechnung-cii')->save('invoices', '2026/RE-2026-0042.xml');

    Storage::disk('invoices')->assertExists('2026/Rechnung_RE-2026-0042.pdf');
    Storage::disk('invoices')->assertExists('2026/RE-2026-0042.xml');
    expect($pdf)->toBe('2026/Rechnung_RE-2026-0042.pdf')
        ->and(Storage::disk('invoices')->get($xml))->toContain('xrechnung_3.0');
});

it('stores on the configured disk by default', function () {
    Storage::fake('archive');
    config()->set('quittio.storage.disk', 'archive');

    Quittio::make(invoice())->format('zugferd')->render()->save(path: '2026/');

    Storage::disk('archive')->assertExists('2026/Rechnung_RE-2026-0042.pdf');
});

it('renders in the queue', function () {
    Queue::fake();
    Quittio::make(invoice())->format('zugferd')->queue()->save('invoices', 'queued/');

    Queue::assertPushed(RenderInvoice::class, function (RenderInvoice $job): bool {
        Storage::fake('invoices');
        $job->handle(app(QuittioManager::class));
        Storage::disk('invoices')->assertExists('queued/Rechnung_RE-2026-0042.pdf');

        return $job->queue === 'quittio';
    });
});

it('builds invoices from models', function () {
    $order = new class implements ProvidesInvoiceData
    {
        public function toInvoiceData(): InvoiceData
        {
            return Seller::apply(InvoiceBuilder::make())
                ->number('RE-1')
                ->issueDate('2026-10-01')
                ->deliveryDate('2026-09-30')
                ->buyer(fn ($b) => $b->name('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
                ->line(fn ($l) => $l->name('Beratung')->quantity(2, 'HUR')->netPrice('100.00')->vat(VatCategory::StandardRate, '19'))
                ->build();
        }
    };

    $result = Quittio::make($order)->format('cii')->render();

    expect($result->data()->totals?->amountDue->getAmount()->toString())->toBe('238.00')
        ->and($result->data()->dueDate?->toIsoString())->toBe('2026-10-15')
        ->and($result->xml())->toContain('DE02120300000000202051');
});

it('marks invoices of small business sellers from the config', function () {
    config()->set('quittio.seller.small_business', true);
    $builder = Seller::apply(InvoiceBuilder::make());

    expect($builder->number('RE-1')->issueDate('2026-10-01')
        ->buyer(fn ($b) => $b->name('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->line(fn ($l) => $l->name('Beratung')->quantity(1, 'HUR')->netPrice('100.00')->vat(VatCategory::Exempt, '0', 'Kleinunternehmer'))
        ->build()->meta->hasFlag(Meta::FLAG_SMALL_BUSINESS))->toBeTrue();
});

it('renders documents', function () {
    $quote = DocumentBuilder::quote()->number('AN-1')->issueDate('2026-10-01')
        ->seller(Seller::fromConfig())
        ->buyer(fn ($b) => $b->name('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->line(fn ($l) => $l->name('Beratung')->quantity(2, 'HUR')->netPrice('100.00')->vat(VatCategory::StandardRate, '19'));

    $htmlOnly = Quittio::document($quote)->locale('de')->htmlOnly()->render();
    $html = $htmlOnly->html();
    $pdf = Quittio::document($quote)->locale('de')->render();

    expect($html)->toContain('<h1>Angebot</h1>')
        ->and($html)->toContain('Auftrag erteilt:')
        ->and($htmlOnly->hasPdf())->toBeFalse()
        ->and($htmlOnly->mimeType())->toBe('text/html')
        ->and($pdf->hasPdf())->toBeTrue()
        ->and($pdf->pdf())->toStartWith('%PDF-');
});

it('fakes rendering in tests', function () {
    $fake = Quittio::fake();
    Quittio::assertNothingRendered();

    Quittio::make(InvoiceData::factory()->withLines(3)->make())->format('zugferd')->render();
    Quittio::make(InvoiceData::factory()->reverseCharge()->make())->format('xrechnung-cii')->render();

    Quittio::assertRenderedCount(2);
    Quittio::assertRendered(fn (RenderedInvoice $r): bool => $r->data()->totals?->amountDue->isEqualTo('166.60') === true);
    expect($fake->rendered()[1]->xml())->toStartWith('<?xml');
});

it('fakes the format and profile choice of a real run', function () {
    Event::fake();
    $fake = Quittio::fake();

    // Automatic choice: the German country rules pick XRechnung for a Leitweg-ID.
    expect(Quittio::make(invoice())->render()->format())->toBe('zugferd')
        ->and(Quittio::make(invoice())->render()->profile()?->key())->toBe('en16931')
        ->and(Quittio::make(Scenarios::make('simple')->buyerReference('04011000-12345-03')->build())->render()->profile()?->key())->toBe('xrechnung')
        // Profile keys count like Profile objects; aliases are resolved.
        ->and(Quittio::make(invoice())->format('factur-x')->profile('extended')->render()->profile()?->key())->toBe('extended')
        ->and(Quittio::make(invoice())->format('pdf')->render()->profile())->toBeNull();

    $both = Quittio::make(invoice())->formats(['zugferd', 'xrechnung-ubl'])->render();
    expect($both->formats())->toBe(['zugferd', 'xrechnung-ubl'])
        ->and($both->hasPdf())->toBeTrue()
        ->and($both->as('xrechnung-ubl')->profile()?->key())->toBe('xrechnung')
        ->and($both->as('xrechnung-ubl')->hasPdf())->toBeFalse()
        ->and($both->data()->dueDate?->toIsoString())->toBe('2026-10-17');

    config()->set('quittio.defaults.format', 'cii');
    config()->set('quittio.defaults.profile', 'basic');
    Quittio::flush();
    expect(Quittio::make(invoice())->render()->format())->toBe('cii')
        ->and(Quittio::make(invoice())->render()->profile()?->key())->toBe('basic')
        ->and(fn () => Quittio::make(invoice())->format('nope')->render())->toThrow(ConfigurationException::class);

    config()->set('quittio.defaults.format', 'auto');
    Quittio::flush();
    expect(Quittio::make(invoice())->render()->format())->toBe('zugferd')
        ->and($fake->rendered())->toHaveCount(9);
    Event::assertNothingDispatched();
});

it('fakes documents and records them', function () {
    $fake = Quittio::fake();
    $quote = DocumentBuilder::quote()->number('AN-1')->issueDate('2026-10-01')
        ->seller(Seller::fromConfig())
        ->buyer(fn ($b) => $b->name('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->line(fn ($l) => $l->name('Beratung')->quantity(2, 'HUR')->netPrice('100.00')->vat(VatCategory::StandardRate, '19'));

    $html = Quittio::document($quote)->htmlOnly()->render();
    $pdf = Quittio::document($quote)->render();

    expect($html->hasPdf())->toBeFalse()
        ->and($html->mimeType())->toBe('text/html')
        ->and(fn () => $html->pdf())->toThrow(LogicException::class)
        ->and($pdf->hasPdf())->toBeTrue()
        ->and($fake->documents())->toBe([$html, $pdf])
        ->and(Quittio::documents())->toHaveCount(2);
    Quittio::assertDocumentRendered(fn (RenderedDocument $d): bool => $d->data()->number === 'AN-1' && ! $d->hasPdf());
    Quittio::assertNothingRendered();
});

it('uses extended renderers', function () {
    $html = null;
    Quittio::extend('renderer', 'callback', function () use (&$html) {
        return new CallbackRenderer(function (string $markup) use (&$html): string {
            $html = $markup;
            $mpdf = new Mpdf\Mpdf(['tempDir' => sys_get_temp_dir().'/quittio-mpdf']);
            $mpdf->WriteHTML('<p>own renderer</p>');

            return (string) $mpdf->Output('', 'S');
        });
    });
    config()->set('quittio.pdf.renderer', 'callback');
    Quittio::flush();

    $result = Quittio::make(invoice())->format('zugferd')->render();

    expect($html)->toContain('Webentwicklung')
        ->and((new PdfEmbeddedFileExtractor)->extract($result->pdf())[1] ?? null)->toBe($result->xml());
});

it('uses extended validators listed in the levels', function () {
    Quittio::extend('validator', 'always-warn', fn () => new class implements ValidationDriver
    {
        public function name(): string
        {
            return 'always-warn';
        }

        public function levels(): array
        {
            return [ValidationLevel::L3];
        }

        public function supports(ValidationSubject $subject): bool
        {
            return true;
        }

        public function validate(ValidationSubject $subject): ValidationResult
        {
            return new ValidationResult([new ValidationMessage(Severity::Warning, 'CUSTOM-1', 'Custom warning', ValidationLevel::L3)]);
        }
    });
    config()->set('quittio.validation.levels', ['php', 'xsd', 'always-warn']);
    Quittio::flush();

    expect(Quittio::make(invoice())->format('cii')->render()->validation()->has('CUSTOM-1'))->toBeTrue();

    config()->set('quittio.validation.block_on_warnings', true);
    Quittio::flush();
    expect(fn () => Quittio::make(invoice())->format('cii')->render())->toThrow(ValidationException::class, 'blocking warnings');
});

it('renders on the configured paper size', function () {
    config()->set('quittio.pdf.paper', 'Letter');
    Quittio::flush();

    expect(Quittio::make(invoice())->format('pdf')->render()->pdf())->toMatch('#/MediaBox\s*\[\s*0 0 612\.000 792\.000\s*\]#');
});

it('checks VAT identifiers online when the VIES driver is enabled', function () {
    $verifier = new class implements VatIdVerifier
    {
        /** @var list<string> */
        public array $checked = [];

        public function verify(string $vatId): VatIdVerification
        {
            $this->checked[] = $vatId;

            return new VatIdVerification($vatId, $vatId !== 'DE987654321');
        }
    };
    app()->instance(VatIdVerifier::class, $verifier);

    expect(Quittio::make(invoice())->format('pdf')->validate(false)->render()->validation()->has('QUITTIO-VIES'))->toBeFalse()
        ->and($verifier->checked)->toBe([]);

    config()->set('quittio.validation.drivers.vies.enabled', true);
    Quittio::flush();
    $result = Quittio::make(invoice())->format('zugferd')->validate(false)->render();

    expect($verifier->checked)->toContain('DE987654321')
        ->and($result->validation()->has('QUITTIO-VIES'))->toBeTrue()
        ->and(Quittio::make(invoice())->format('pdf')->validate(false)->render()->validation()->has('QUITTIO-VIES'))->toBeTrue();
});

it('builds the VIES client from the configuration', function () {
    config()->set('quittio.validation.drivers.vies', ['enabled' => true, 'timeout' => 3, 'cache_ttl' => 60]);
    Quittio::flush();
    $drivers = array_values(array_filter(Quittio::core()->validators(), static fn ($d): bool => $d instanceof PhpRulesDriver));
    $verifier = (new ReflectionProperty(PhpRulesDriver::class, 'vatIdVerifier'))->getValue($drivers[0]);

    expect($verifier)->toBeInstanceOf(ViesVerifier::class)
        ->and((new ReflectionProperty(ViesVerifier::class, 'timeout'))->getValue($verifier))->toBe(3)
        ->and((new ReflectionProperty(ViesVerifier::class, 'cacheTtl'))->getValue($verifier))->toBe(60);
});

it('reads and validates files', function () {
    $pdf = Quittio::make(invoice())->format('zugferd')->render()->pdf();

    expect(Quittio::read($pdf)->data->number)->toBe('RE-2026-0042')
        ->and(Quittio::validateFile($pdf)->isValid())->toBeTrue();
});

it('uses label overrides from the application', function () {
    app('translator')->addLines(['labels.gross_total' => 'Rechnungsbetrag'], 'de', 'quittio');

    expect(Quittio::make(invoice())->format('html')->render()->html())->toContain('Rechnungsbetrag');
});

it('applies design settings from the configuration', function () {
    config()->set('quittio.pdf.colors.primary', '#123456');
    config()->set('quittio.pdf.texts.intro', 'Vielen Dank für Ihren **Auftrag**.');
    Quittio::flush();

    $html = Quittio::make(invoice())->format('html')->render()->html();

    expect($html)->toContain('#123456')
        ->and($html)->toContain('<strong>Auftrag</strong>');
});

it('reports missing validator programs instead of failing the render', function () {
    config([
        'quittio.validation.levels' => ['php', 'xsd', 'kosit', 'verapdf'],
        'quittio.validation.drivers.kosit' => ['mode' => 'cli', 'jar' => '/nonexistent/validator.jar', 'java' => '/nonexistent/java', 'fail_on_unavailable' => false],
        'quittio.validation.drivers.verapdf' => ['mode' => 'cli', 'binary' => '/nonexistent/verapdf', 'fail_on_unavailable' => false],
    ]);
    Quittio::flush();

    $result = Quittio::make(Scenarios::simple()->build())->format('zugferd')->render();
    $codes = array_map(fn ($m) => $m->code, $result->validation()->messages);

    expect($codes)->toContain('KOSIT-UNAVAILABLE')
        ->toContain('VERAPDF-UNAVAILABLE')
        ->and($result->pdf())->toStartWith('%PDF');
});
