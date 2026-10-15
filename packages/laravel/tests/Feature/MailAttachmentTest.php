<?php

declare(strict_types=1);

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Quittio\Core\Testing\Scenarios;
use Quittio\Laravel\Facades\Quittio;

/**
 * The bytes Laravel would attach.
 */
function attachmentData(Attachment $attachment): string
{
    return $attachment->attachWith(static fn (): string => throw new LogicException('Attached from a path.'), static fn (Closure $data): string => $data());
}

it('attaches the main output with its file name and MIME type', function (string $format, string $name, string $mime) {
    $result = Quittio::make(Scenarios::make('simple')->build())->format($format)->render();
    $attachment = $result->toMailAttachment();

    expect($attachment->as)->toBe($name)
        ->and($attachment->mime)->toBe($mime)
        ->and(attachmentData($attachment))->toBe($result->contents());
})->with([
    'ZUGFeRD' => ['zugferd', 'Rechnung_RE-2026-0042.pdf', 'application/pdf'],
    'XRechnung' => ['cii', 'Rechnung_RE-2026-0042.xml', 'application/xml'],
]);

it('attaches the XML of a ZUGFeRD run as a second file', function () {
    $result = Quittio::make(Scenarios::make('simple')->build())->formats(['zugferd', 'xrechnung-cii'])->render();
    $mail = (new Mailable)->attach($result)->attach($result->as('xrechnung-cii'));

    expect($mail->hasAttachment($result))->toBeTrue()
        ->and($mail->hasAttachment($result->as('xrechnung-cii')))->toBeTrue()
        ->and(attachmentData($result->as('xrechnung-cii')->toMailAttachment()))->toStartWith('<?xml');
});

it('attaches documents as PDF or HTML', function () {
    $quote = Quittio::quote()->number('AN-1')->issueDate('2026-10-01')
        ->buyer(fn ($b) => $b->name('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->line(fn ($l) => $l->name('Beratung')->quantity(2, 'HUR')->netPrice('100.00'));

    $pdf = Quittio::document($quote)->render()->toMailAttachment();
    $html = Quittio::document($quote)->htmlOnly()->render()->toMailAttachment();

    expect($pdf->mime)->toBe('application/pdf')
        ->and(attachmentData($pdf))->toStartWith('%PDF-')
        ->and($html->mime)->toBe('text/html')
        ->and((string) $html->as)->toEndWith('.html');
});
