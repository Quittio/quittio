<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Tests\Support\Validate;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Core\Xml\XmlBuilder;

function invalidCharactersInvoice(string $note): InvoiceData
{
    return (new En16931Calculator)->calculate(Invoices::simple()->note($note)->build());
}

it('tells XML text from control characters and invalid UTF-8', function (string $value, bool $valid): void {
    expect(XmlBuilder::isXmlText($value))->toBe($valid);
})->with([
    'plain text' => ['Hauptstraße 1 – Büro', true],
    'tab, line feed and carriage return' => ["a\tb\nc\r\n", true],
    'emoji' => ['Thanks 🙂', true],
    'vertical tab' => ["Item\x0Bdescription", false],
    'null byte' => ["a\0b", false],
    'escape' => ["\x1B[0m", false],
    'non-character U+FFFE' => ["a\u{FFFE}b", false],
    'invalid UTF-8' => ["Gr\xFC\xDFe", false],
]);

it('reports the field with control characters or invalid UTF-8', function (): void {
    $invoice = invalidCharactersInvoice("Please pay\x0Bpromptly");
    $invoice = $invoice->with(['seller' => $invoice->seller->with(['name' => "Seller \xFCLtd"])]);

    $result = Validate::data($invoice, calculate: false);
    $messages = array_values(array_filter($result->messages, fn ($m) => $m->code === 'QUITTIO-INVALID-CHARACTERS'));

    expect(array_map(fn ($m) => [$m->severity, $m->path], $messages))->toEqualCanonicalizing([
        [Severity::Error, 'seller.name'],
        [Severity::Error, 'notes[0].content'],
    ])
        ->and($messages[0]->message)->toContain('control characters or invalid UTF-8')
        ->and($result->isValid())->toBeFalse();
});

it('does not report tabs and line breaks', function (): void {
    $result = Validate::data(invalidCharactersInvoice("Line one\nLine two\twith tab"), calculate: false);

    expect(Validate::codes($result))->not->toContain('QUITTIO-INVALID-CHARACTERS');
});

it('refuses to write XML that would not be well-formed', function (string $writer): void {
    $invoice = invalidCharactersInvoice("Please pay\x0Bpromptly");
    $profile = ProfileRegistry::withDefaults()->get('en16931');

    ($writer === 'cii' ? new CiiWriter : new UblWriter)->serialize($invoice, $profile);
})->with(['cii', 'ubl'])->throws(InvalidInvoiceData::class, 'QUITTIO-INVALID-CHARACTERS');
