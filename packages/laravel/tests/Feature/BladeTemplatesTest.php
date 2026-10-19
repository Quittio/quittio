<?php

declare(strict_types=1);

use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Pdf\Qr\PaymentSlip;
use Quittio\Core\Pdf\Qr\ProvidesPaymentSlip;
use Quittio\Core\Testing\Scenarios;
use Quittio\Country\DE\Tax\VatPreset;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Support\Preview;

it('renders line groups with subtotals to PDF with the Blade template', function () {
    // Laravel turns PHP warnings into exceptions: mPDF must not raise any.
    $invoice = Scenarios::base()
        ->group('g1', 'Gruppe 1')
        ->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice('10.00')->vat(VatPreset::Standard19)->group('g1'))
        ->line(fn ($l) => $l->name('B')->quantity(1, UnitCode::Piece)->netPrice('20.00')->vat(VatPreset::Reduced7));

    expect(Quittio::make($invoice->build())->format('zugferd')->render()->pdf())->toStartWith('%PDF');
});

it('hides the direct debit notice on refunds and self-billed invoices in the Blade template', function (string $type, bool $shown) {
    $invoice = Scenarios::simple()
        ->type($type)
        ->payment(PaymentMeansCode::SepaDirectDebit)
        ->directDebit('MANDAT-1', 'DE98ZZZ09999999999', 'DE02120300000000202051');
    if ($type === '381') {
        $invoice->precedingInvoice('RE-2026-0001', '2026-09-01');
    }
    if ($type === '389') {
        $invoice->de()->selfBilled();
    }

    $html = (string) Quittio::make($invoice->build())->format('html')->validate(false)->render()->html();

    expect(str_contains($html, 'MANDAT-1'))->toBe($shown);
})->with([
    'invoice' => ['380', true],
    'credit note' => ['381', false],
    'self-billed' => ['389', false],
]);

it('does not preview partials as templates', function () {
    enablePreview();

    expect(app(Preview::class)->allowsTemplate('quittio::templates.default.layout'))->toBeTrue()
        ->and(app(Preview::class)->allowsTemplate('quittio::templates.default.partials.lines'))->toBeFalse();
    $this->get('/_quittio/preview/simple?template=quittio::templates.default.partials.lines')->assertNotFound();
});

it('renders only the configured, shipped and allowed views', function () {
    view()->addNamespace('fixtures', __DIR__.'/../Fixtures/views');
    $render = fn (string $template) => fn () => Quittio::make(Scenarios::simple()->build())->format('html')->validate(false)->template($template)->render();

    expect($render('fixtures::invoice'))->toThrow(InvalidArgumentException::class, 'is no invoice template')
        ->and($render('quittio::templates.default.partials.lines'))->toThrow(InvalidArgumentException::class, 'is no invoice template');

    config()->set('quittio.pdf.templates', ['fixtures::*']);
    Quittio::flush();
    config()->set('quittio.validation.mandatory_field_guard', 'off');
    Quittio::flush();

    expect($render('fixtures::invoice')()->html())->toContain('RE-2026-0042')
        ->and($render('quittio::templates.default.layout')()->html())->toContain('Webentwicklung');
});

it('shows the refund of a negative invoice as a positive amount in the Blade template', function () {
    $original = Quittio::make(Scenarios::simple()->build())->format('cii')->render()->data();
    $cancellation = Quittio::cancellation($original, 'ST-1', 'Doppelt', 'negative_invoice')->build();

    $html = (string) Quittio::make($cancellation)->format('html')->validate(false)->render()->html();

    expect(preg_match('#<tr class="grand"><td class="grand-label">([^<]*)</td><td class="right amount">([^<]*)</td>#u', $html, $due))->toBe(1)
        ->and($due[1])->toBe('Erstattungsbetrag')
        ->and($due[2])->toContain('2.747,71')->not->toContain('-')->not->toContain('−');
});

it('prints the payment slip of a generator that provides one in the Blade template', function () {
    Quittio::core()->setPaymentQrGenerator(new class implements ProvidesPaymentSlip
    {
        public function key(): string
        {
            return 'swiss-qr-bill';
        }

        public function supports(InvoiceData $invoice): bool
        {
            return true;
        }

        public function payload(InvoiceData $invoice, array $options = []): ?string
        {
            return "SPC\n0200\n1";
        }

        public function slip(InvoiceData $invoice, string $payload, string $locale): PaymentSlip
        {
            return new PaymentSlip('Payment part', 'Receipt', [['heading' => 'Account / Payable to', 'lines' => ['CH44 3199 9123 0008 8901 2']]], [], 'Currency', 'Amount', 'CHF', '1 590.00', 'Acceptance point', 'Separate before paying in');
        }
    });
    $html = (string) Quittio::make(Scenarios::simple()->build())->format('html')->validate(false)->render()->html();
    Quittio::flush();

    expect($html)->toContain('<pagebreak page-selector="slip" />')
        ->and($html)->toContain('CH44 3199 9123 0008 8901 2')
        ->and($html)->toContain('1 590.00')
        ->and($html)->not->toContain('class="qr"');
});
