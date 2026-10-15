<?php

declare(strict_types=1);

use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Testing\Scenarios;
use Quittio\Laravel\Facades\Quittio;

/*
| mPDF silently ignores margin and padding of blocks inside table cells and, with
| simpleTables, the borders of table cells. These tests measure the rendered PDF, so
| that such spacing errors are caught even if the HTML looks right.
*/

/**
 * Top edge in millimetres of the first word matching $word on page 1.
 */
function wordTop(string $pdf, string $word, int $occurrence = 0): float
{
    $file = tempnam(sys_get_temp_dir(), 'quittio-layout-');
    file_put_contents((string) $file, $pdf);
    exec(sprintf('pdftotext -f 1 -l 1 -bbox %s -', escapeshellarg((string) $file)), $lines);
    unlink((string) $file);

    $found = [];
    foreach ($lines as $line) {
        if (preg_match('/yMin="([0-9.]+)".*>'.preg_quote($word, '/').'</', $line, $m) === 1) {
            $found[] = (float) $m[1] / 72 * 25.4;
        }
    }

    return $found[$occurrence] ?? throw new RuntimeException(sprintf('Word "%s" not found.', $word));
}

function popplerAvailable(): bool
{
    exec('command -v pdftotext', $output, $code);

    return $code === 0;
}

it('puts payment terms and bank details side by side below their headings', function () {
    $pdf = Quittio::make(Scenarios::make('simple')->build())->format('pdf')->validate(false)->render()->pdf();
    // "Zahlung" heads the payment terms, "Bankverbindung" the bank details next to them; the
    // heading is followed by more than its line height (cell padding, not block margins).
    $heading = wordTop($pdf, 'Zahlung');

    expect(abs(wordTop($pdf, 'Bankverbindung') - $heading))->toBeLessThan(0.5)
        ->and(abs(wordTop($pdf, 'DE02') - wordTop($pdf, 'Bitte')))->toBeLessThan(0.5)
        ->and(wordTop($pdf, 'Bitte') - $heading)->toBeGreaterThan(5.2)
        ->and(wordTop($pdf, 'Skonto') - wordTop($pdf, 'Bitte'))->toBeGreaterThan(4.5);
})->group('visual')->skip(fn () => ! popplerAvailable(), 'pdftotext is not available.');

it('keeps the address below the sender line', function () {
    $pdf = Quittio::make(Scenarios::make('simple')->build())->format('pdf')->validate(false)->render()->pdf();
    // The sender line (9 pt) is followed by 1.2 mm padding, the rule and 2.2 mm space.
    $gap = wordTop($pdf, 'Kunde') - wordTop($pdf, 'Musterstadt');

    expect($gap)->toBeGreaterThan(4.5);
})->group('visual')->skip(fn () => ! popplerAvailable(), 'pdftotext is not available.');

it('keeps the address field at the letter layout position, whatever the logo height', function (string $layout, float $top) {
    $logo = sys_get_temp_dir().'/quittio-layout-logo.png';
    $image = imagecreatetruecolor(300, 120);
    imagepng($image, $logo);
    $presentation = new Presentation(letterLayout: $layout, logo: $logo);

    $pdf = Quittio::make(Scenarios::make('simple')->build())->format('pdf')->presentation($presentation)->validate(false)->render()->pdf();
    // The sender line at the top of the address field, the recipient right below it.
    $sender = wordTop($pdf, 'Musterstadt');
    $recipient = wordTop($pdf, 'Kunde');

    expect($sender)->toBeGreaterThan($top - 0.5)
        ->and($sender)->toBeLessThan($top + 1.5)
        ->and($recipient)->toBeGreaterThan($top + 6.5)
        ->and($recipient)->toBeLessThan($top + 9.0);
})->with([
    'DIN 5008 form A' => ['din5008a', 27.0],
    'DIN 5008 form B' => ['din5008b', 45.0],
])->group('visual')->skip(fn () => ! popplerAvailable() || ! extension_loaded('gd'), 'pdftotext or GD is not available.');
