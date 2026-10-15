<?php

declare(strict_types=1);

use Quittio\Core\Calculation\DeclaredAmounts;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\TotalsCalculator;
use Quittio\Core\Corrections\CancellationHistory;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Pdf\EmbeddedXml;
use Quittio\Core\Pdf\PdfRenderer;
use Quittio\Core\Pdf\PostProcessing\PdfPostProcessor;
use Quittio\Core\Pdf\RenderRequest;
use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;

/**
 * A custom calculator that counts its calls and delegates to the EN 16931 calculator.
 */
function countingCalculator(): TotalsCalculator
{
    return new class implements TotalsCalculator
    {
        public int $calls = 0;

        public function calculate(InvoiceData $invoice): InvoiceData
        {
            $this->calls++;

            return (new En16931Calculator)->calculate($invoice);
        }
    };
}

it('aligns partial cancellations with the calculator of the instance', function () {
    $calculator = countingCalculator();
    $quittio = new Quittio(calculator: $calculator);
    $original = (new En16931Calculator)->calculate(Scenarios::base()
        ->line(fn ($l) => $l->name('Teil')->quantity(3, 'H87')->netPrice('3.33')->vat(VatCategory::StandardRate, '19'))
        ->build());
    $first = (new En16931Calculator)->calculate($quittio->cancellation($original, 'ST-1', lines: [['line' => $original->lines[0]->id, 'quantity' => '1']])->build());
    $history = (new CancellationHistory)->withCancellation($original, $first);

    $quittio->cancellation($original, 'ST-2', lines: [['line' => $original->lines[0]->id, 'quantity' => '1']], history: $history)->build();

    expect($calculator->calls)->toBeGreaterThan(0);
});

it('checks declared amounts with the given calculator', function () {
    $calculator = countingCalculator();
    $data = (new En16931Calculator)->calculate(Scenarios::make('simple')->build());

    expect(DeclaredAmounts::needed($data, $calculator))->toBeFalse()
        ->and($calculator->calls)->toBe(1);
});

it('post-processes the PDFs of renderers that cannot embed with the configured post processor', function () {
    $renderer = new class implements PdfRenderer
    {
        public function render(RenderRequest $request): string
        {
            return "%PDF-1.4\n% plain";
        }

        public function supportsEmbedding(): bool
        {
            return false;
        }
    };
    $processor = new class implements PdfPostProcessor
    {
        public ?EmbeddedXml $embedded = null;

        public function process(string $pdf, ?EmbeddedXml $embedded, string $title, ?string $author = null): string
        {
            $this->embedded = $embedded;

            return "%PDF-1.7\n% processed";
        }
    };

    $result = Quittio::create(renderer: $renderer)->setPostProcessor($processor)->make(Scenarios::make('simple')->build())->format('zugferd')->render();

    expect($result->pdf())->toBe("%PDF-1.7\n% processed")
        ->and($processor->embedded?->filename)->toBe('factur-x.xml')
        ->and($processor->embedded?->description)->toBe('Factur-X/ZUGFeRD Invoice')
        ->and($processor->embedded?->version)->toBe('1.0');
});
