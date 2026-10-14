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

it('separates the payment blocks', function () {
    $pdf = Quittio::make(Scenarios::make('simple')->build())->format('pdf')->validate(false)->render()->pdf();
    // "Skonto" ends the payment terms, "Bankverbindung" starts the bank details (normal line pitch is about 4 mm).
    $gap = wordTop($pdf, 'Bankverbindung') - wordTop($pdf, 'Skonto');

    expect($gap)->toBeGreaterThan(7.0);
})->group('visual')->skip(fn () => ! popplerAvailable(), 'pdftotext is not available.');

it('keeps the address below the sender line', function () {
    $pdf = Quittio::make(Scenarios::make('simple')->build())->format('pdf')->validate(false)->render()->pdf();
    // The sender line (6.5 pt) is followed by 1 mm padding, the border and 2 mm space.
    $gap = wordTop($pdf, 'Kunde') - wordTop($pdf, 'Musterstadt');

    expect($gap)->toBeGreaterThan(4.5);
})->group('visual')->skip(fn () => ! popplerAvailable(), 'pdftotext is not available.');

it('keeps the address field at the letter layout position, whatever the logo height', function (string $layout, float $top) {
    $logo = sys_get_temp_dir().'/quittio-layout-logo.png';
    $image = imagecreatetruecolor(300, 120);
    imagepng($image, $logo);
    $presentation = new Presentation(letterLayout: $layout, logo: $logo);

    $pdf = Quittio::make(Scenarios::make('simple')->build())->format('pdf')->presentation($presentation)->validate(false)->render()->pdf();
    // DIN 5008: the sender line closes the 17.7 mm "Zusatz- und Vermerkzone", the recipient
    // starts in the "Anschriftzone" below it.
    $recipient = wordTop($pdf, 'Kunde');

    expect($recipient)->toBeGreaterThan($top + 17.5)
        ->and($recipient)->toBeLessThan($top + 20.0);
})->with([
    'DIN 5008 form A' => ['din5008a', 27.0],
    'DIN 5008 form B' => ['din5008b', 45.0],
])->group('visual')->skip(fn () => ! popplerAvailable() || ! extension_loaded('gd'), 'pdftotext or GD is not available.');
