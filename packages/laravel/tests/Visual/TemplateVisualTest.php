<?php

declare(strict_types=1);

use Quittio\Core\Testing\Scenarios;
use Quittio\Laravel\Facades\Quittio;

/*
| Visual regression of the default Blade template (sample scenarios).
| The first page of each PDF is rasterised with pdftoppm and compared pixel by pixel
| with the baseline in __snapshots__. macOS and the Linux CI image (tools/visual/check-linux.sh)
| produce the same images. A missing baseline fails the test; with QUITTIO_UPDATE_BASELINES=1
| it is written instead. Delete a baseline and run with the flag to accept an intended change.
*/

const VISUAL_TOLERANCE = 0.002;

function pdftoppmAvailable(): bool
{
    exec('command -v pdftoppm', $output, $code);

    return $code === 0 && extension_loaded('gd');
}

function rasterise(string $pdf): string
{
    $base = sys_get_temp_dir().'/quittio-visual-'.bin2hex(random_bytes(4));
    file_put_contents($base.'.pdf', $pdf);
    exec(sprintf('pdftoppm -r 50 -png -f 1 -l 1 -singlefile %s %s', escapeshellarg($base.'.pdf'), escapeshellarg($base)), $output, $code);
    unlink($base.'.pdf');
    if ($code !== 0 || ! is_file($base.'.png')) {
        throw new RuntimeException('pdftoppm failed.');
    }

    return $base.'.png';
}

/**
 * Share of pixels whose colour differs noticeably.
 */
function pixelDifference(string $expected, string $actual): float
{
    $a = imagecreatefrompng($expected);
    $b = imagecreatefrompng($actual);
    if ($a === false || $b === false) {
        throw new RuntimeException('Unable to read the images.');
    }
    if (imagesx($a) !== imagesx($b) || imagesy($a) !== imagesy($b)) {
        return 1.0;
    }

    $different = 0;
    $width = imagesx($a);
    $height = imagesy($a);
    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $height; $y++) {
            $ca = imagecolorat($a, $x, $y);
            $cb = imagecolorat($b, $x, $y);
            $delta = abs(($ca >> 16 & 0xFF) - ($cb >> 16 & 0xFF)) + abs(($ca >> 8 & 0xFF) - ($cb >> 8 & 0xFF)) + abs(($ca & 0xFF) - ($cb & 0xFF));
            if ($delta > 48) {
                $different++;
            }
        }
    }

    return $different / ($width * $height);
}

it('renders the default template like the baseline', function (string $scenario) {
    $pdf = Quittio::make(Scenarios::make($scenario)->build())->format('pdf')->validate(false)->render()->pdf();
    $actual = rasterise($pdf);
    $directory = __DIR__.'/__snapshots__';
    $baseline = $directory.'/'.$scenario.'.png';

    if (! is_file($baseline)) {
        if (getenv('QUITTIO_UPDATE_BASELINES') !== '1' || getenv('CI') !== false) {
            unlink($actual);
            throw new RuntimeException(sprintf('Missing baseline %s; run QUITTIO_UPDATE_BASELINES=1 vendor/bin/pest --group=visual locally to create it.', $baseline));
        }
        @mkdir(dirname($baseline), 0775, true);
        rename($actual, $baseline);
        expect($baseline)->toBeFile();

        return;
    }

    $difference = pixelDifference($baseline, $actual);
    if ($difference > VISUAL_TOLERANCE) {
        copy($actual, $directory.'/'.$scenario.'.actual.png');
    }
    unlink($actual);

    expect($difference)->toBeLessThanOrEqual(VISUAL_TOLERANCE);
})->with(['simple', 'many-lines', 'long-texts', 'mixed-vat', 'reverse-charge', 'cancellation', 'sub-lines', 'foreign-currency', 'b2c-gross', 'intra-eu', 'allowances'])
    ->group('visual')
    ->skip(fn () => ! pdftoppmAvailable(), 'pdftoppm or GD is not available.');
