<?php

declare(strict_types=1);

use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Testing\Scenarios;
use Quittio\Laravel\Facades\Quittio;

const SCRIPT = '<script>alert("x")</script>';

/**
 * HTML of the default Blade template for an invoice with markup in every user text.
 */
function hostileHtml(): string
{
    $invoice = Scenarios::base()
        ->buyer(fn ($b) => $b->name('Kunde '.SCRIPT)->address('Weg 1 '.SCRIPT, '54321', 'Stadt', 'DE'))
        ->buyerReference('PO '.SCRIPT)
        ->note('Hinweis '.SCRIPT.' <img src=x onerror=alert(1)>', 'AAI')
        ->presentation(['introText' => 'Intro '.SCRIPT, 'closingText' => 'Gruß **'.SCRIPT.'**', 'watermark' => '"; } body { background: url(https://x) } /*'])
        ->line(fn ($l) => $l->name('Pos '.SCRIPT)->description('Text '.SCRIPT."\n<iframe src=x>")->quantity(1, 'H87')->netPrice('10.00')->vat(VatCategory::StandardRate, '19')
            ->attribute('Farbe '.SCRIPT, 'rot '.SCRIPT))
        ->build();

    return Quittio::make($invoice)->format('html')->withoutValidation()->render()->html();
}

it('escapes user data in the default Blade template', function (bool $markdown) {
    config()->set('quittio.pdf.markdown', $markdown);
    $html = hostileHtml();

    expect($html)->not->toContain('<script', '<img', '<iframe', 'onerror=', 'url(https://x)')
        ->toContain('Kunde &lt;script&gt;', 'Pos &lt;script&gt;', 'Text &lt;script&gt;', 'Hinweis &lt;script&gt;', 'Intro &lt;script&gt;', 'Farbe &lt;script&gt;');
})->with(['plain text' => [false], 'Markdown' => [true]]);

it('renders the allowed Markdown of descriptions and closing texts as HTML', function () {
    config()->set('quittio.pdf.markdown', true);

    expect(hostileHtml())->toContain('<strong>&lt;script&gt;');
});

it('warns in every template partial that prints pre-escaped HTML', function () {
    $directory = dirname(__DIR__, 2).'/resources/views/templates/default/partials';
    foreach (glob($directory.'/*.blade.php') ?: [] as $file) {
        $code = (string) file_get_contents($file);
        if (str_contains($code, '{!!')) {
            expect(str_contains($code, 'pre-escaped'))->toBeTrue(basename($file).' prints raw HTML without the warning comment.');
        }
    }
});
