<?php

declare(strict_types=1);

use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Pdf\LogoCache;
use Quittio\Core\Pdf\RemoteImageFetcher;
use Quittio\Core\Pdf\Renderers\MpdfRenderer;
use Quittio\Core\Pdf\RenderRequest;
use Quittio\Core\Quittio;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\View\MandatoryFieldMissing;
use Quittio\Core\View\ViewModelFactory;

it('renders line groups with subtotals to PDF without PHP warnings', function () {
    $invoice = Invoices::base()
        ->group('g1', 'Group 1')
        ->line(fn ($l) => $l->name('A')->quantity(1, UnitCode::Piece)->netPrice('10.00')->vat(VatCategory::StandardRate, '19')->group('g1'))
        ->line(fn ($l) => $l->name('B')->quantity(1, UnitCode::Piece)->netPrice('20.00')->vat(VatCategory::StandardRate, '19'));
    // Like Laravel: every warning is an exception.
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        $pdf = Quittio::create()->make($invoice)->format('pdf')->withoutValidation()->render()->pdf();
    } finally {
        restore_error_handler();
    }

    expect($pdf)->toStartWith('%PDF');
});

it('hides the direct debit notice on refunds and self-billed invoices', function (string $type, bool $shown) {
    $invoice = Invoices::simple()
        ->type($type)
        ->payment(PaymentMeansCode::SepaDirectDebit)
        ->directDebit('MANDATE-1', 'DE98ZZZ09999999999', 'DE02120300000000202051');
    if ($type === '381') {
        $invoice->precedingInvoice('INV-0', '2026-09-01');
    }

    $html = (string) Quittio::create()->make($invoice)->format('html')->withoutValidation()->render()->html();

    expect(str_contains($html, 'MANDATE-1'))->toBe($shown);
})->with([
    'invoice' => ['380', true],
    'credit note' => ['381', false],
    'self-billed' => ['389', false],
]);

/**
 * A PNG that declares $width × $height pixels (header only): enough for getimagesize().
 */
function round3PngHeader(int $width, int $height): string
{
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', (string) gzcompress('')).$chunk('IEND', '');
}

it('refuses logos that would decode to a huge canvas', function (bool $pdfa, bool $dataUri) {
    $dir = sys_get_temp_dir().'/quittio-bomb-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/bomb.png', round3PngHeader(40000, 40000));
    $logo = $dataUri ? 'data:image/png;base64,'.base64_encode(round3PngHeader(40000, 40000)) : $dir.'/bomb.png';

    (new LogoCache($dir.'/cache', pdfa: $pdfa))->resolve($logo);
})->with([
    'file' => [false, false],
    'file for PDF/A' => [true, false],
    'data URI for PDF/A' => [true, true],
])->throws(ConfigurationException::class, '40000 × 40000 pixels')->skip(! extension_loaded('gd'), 'GD is not available.');

it('refuses data URI logos above the size limit', function () {
    (new LogoCache(sys_get_temp_dir().'/quittio-logo-'.bin2hex(random_bytes(4)), pdfa: true))
        ->resolve('data:image/png;base64,'.base64_encode(str_repeat('x', 10 * 1024 * 1024 + 1)));
})->throws(ConfigurationException::class, 'larger than 10 MB');

it('rasterises SVG logos only with the SVG coder and at a bounded size', function () {
    $dir = sys_get_temp_dir().'/quittio-svg-'.bin2hex(random_bytes(4));
    mkdir($dir);
    // PostScript in a file named .svg must not reach Ghostscript through format detection.
    file_put_contents($dir.'/postscript.svg', "%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 300 60\n/Helvetica findfont 30 scalefont setfont 10 20 moveto (PS) show showpage\n");
    // A few bytes that declare a canvas of 300000 × 100000 px.
    file_put_contents($dir.'/huge.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="300000" height="100000"><rect width="300000" height="100000" fill="#1e40af"/></svg>');
    $cache = new LogoCache($dir.'/cache', pdfa: true);

    $huge = (string) $cache->resolve($dir.'/huge.svg');

    expect(array_slice((array) getimagesize($huge), 0, 2))->toBe([600, 200])
        ->and(fn () => $cache->resolve($dir.'/postscript.svg'))->toThrow(ConfigurationException::class, 'cannot be rasterised');
})->skip(! extension_loaded('imagick') || ! extension_loaded('gd'), 'imagick or GD is not available.');

it('downloads remote logos only from public addresses', function (string $address) {
    expect(RemoteImageFetcher::isPublicAddress($address))->toBeFalse();
})->with(['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '::1', '::', 'fe80::1', 'fd00::1', '::ffff:127.0.0.1', '64:ff9b::7f00:1', 'ff02::1', 'fec0::1', 'fecf::1', 'not an ip']);

it('checks every resolved address and every redirect of a remote logo', function () {
    $png = (function (): string {
        ob_start();
        imagepng(imagecreatetruecolor(20, 10));

        return (string) ob_get_clean();
    })();
    $resolver = static fn (string $host): array => match ($host) {
        'cdn.example' => ['93.184.216.34', '2606:4700::1111'],
        'mixed.example' => ['93.184.216.34', '10.0.0.5'],
        'internal.example' => ['192.168.0.10'],
        default => [],
    };
    $connected = [];
    $transport = static function (string $url, string $host, string $ip) use ($png, &$connected): array {
        $connected[] = $host.'@'.$ip;

        return match (true) {
            str_ends_with($url, '/to-internal') => ['status' => 302, 'location' => 'http://internal.example/logo.png', 'body' => ''],
            str_ends_with($url, '/relative') => ['status' => 301, 'location' => '/logo.png', 'body' => ''],
            str_ends_with($url, '/loop') => ['status' => 302, 'location' => '/loop', 'body' => ''],
            default => ['status' => 200, 'location' => null, 'body' => $png],
        };
    };
    $fetcher = new RemoteImageFetcher(resolver: $resolver, transport: $transport);
    $dir = sys_get_temp_dir().'/quittio-remote-'.bin2hex(random_bytes(4));
    $cache = new LogoCache($dir, fetcher: $fetcher);
    $refused = static fn (string $url) => fn () => $cache->resolve($url);

    $inline = (string) $cache->resolve('https://cdn.example/relative');

    expect($inline)->toBe('data:image/png;base64,'.base64_encode($png))
        ->and((new LogoCache($dir, pdfa: true, fetcher: $fetcher))->resolve('https://cdn.example/logo.png'))->toStartWith('data:image/png;base64,')
        ->and($cache->resolve('https://cdn.example/loop'))->toBeNull()
        ->and($connected)->each->toStartWith('cdn.example@93.184.216.34')
        ->and($refused('https://mixed.example/logo.png'))->toThrow(ConfigurationException::class, 'non-public address 10.0.0.5')
        ->and($refused('https://cdn.example/to-internal'))->toThrow(ConfigurationException::class, 'non-public address 192.168.0.10')
        ->and($refused('http://127.0.0.1/logo.png'))->toThrow(ConfigurationException::class, 'non-public address 127.0.0.1')
        ->and($refused('http://[::1]/logo.png'))->toThrow(ConfigurationException::class, 'non-public address ::1');
})->skip(! extension_loaded('gd'), 'GD is not available.');

it('accepts only local files, data URIs and http(s) URLs as logos', function (string $logo) {
    (new LogoCache(sys_get_temp_dir().'/quittio-logo-'.bin2hex(random_bytes(4))))->resolve($logo);
})->with(['ftp://example.com/logo.png', 'php://filter/resource=/etc/passwd', 'phar:///tmp/logo.phar/logo.png', 'gopher://example.com/'])
    ->throws(ConfigurationException::class, 'Logos must be local files, data URIs or http(s) URLs');

it('refuses SVG logos with external references also without PDF/A', function () {
    (new LogoCache(sys_get_temp_dir().'/quittio-logo-'.bin2hex(random_bytes(4))))
        ->resolve('data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><image href="https://example.com/x.png"/></svg>'));
})->throws(ConfigurationException::class, 'references external resources');

it('never hands a remote logo URL to the renderer', function (string $format) {
    $invoice = Invoices::simple()->presentation(new Presentation(logo: 'http://169.254.169.254/latest/meta-data/logo.png'));

    Quittio::create()->make($invoice)->format($format)->withoutValidation()->render();
})->with(['pdf', 'zugferd'])->throws(ConfigurationException::class, 'non-public address 169.254.169.254');

it('requires the unit with the quantity and the full name of the seller', function (string $omit) {
    $dir = sys_get_temp_dir().'/quittio-guard-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $unit = $omit === 'unit' ? '' : '$e($line->unit->label),';
    $seller = $omit === 'name' ? '$e($invoice->seller->displayName)' : '$e($invoice->seller->name)';
    file_put_contents($dir.'/minimal.php', '<?php echo $e($invoice->number), $e($invoice->issueDate), '.$seller.', $e($invoice->totals->amountDue);
        foreach (["seller", "buyer"] as $p) { echo $e($invoice->{$p}->address->street), $e($invoice->{$p}->address->postcode), $e($invoice->{$p}->address->city); }
        echo $e($invoice->buyer->name);
        foreach ($invoice->lines as $line) { echo $e($line->name), $e($line->quantity), '.$unit.' ""; }
        foreach ($invoice->vatRows as $row) { echo $e($row->label), $e($row->amount); }');
    $invoice = Invoices::simple()->seller(fn ($s) => $s->tradingName('Seller Shop'));

    $render = fn () => Quittio::create(['template_paths' => [$dir]])->make($invoice)->format('html')->withoutValidation()->template('minimal')->render();

    if ($omit === 'nothing') {
        expect($render()->html())->toContain('Consulting');
    } else {
        expect($render)->toThrow(MandatoryFieldMissing::class, $omit === 'unit' ? 'Quantity and unit of every line' : 'Seller name (BT-27)');
    }
})->with(['nothing', 'unit', 'name']);

it('shows the refund of a negative invoice as a positive amount and keeps the XML', function () {
    $quittio = Quittio::create();
    $original = $quittio->make(Invoices::simple())->format('cii')->render()->data();
    $cancellation = $quittio->cancellation($original, 'CN-1', 'Duplicate', 'negative_invoice');

    $html = (string) $quittio->make($cancellation)->format('html')->render()->html();
    $xml = $quittio->make($quittio->cancellation($original, 'CN-1', 'Duplicate', 'negative_invoice'))->format('cii')->render()->xml();

    expect(preg_match('#<tr class="due"><td>([^<]*)</td><td class="right">([^<]*)</td>#u', $html, $due))->toBe(1)
        ->and($due[1])->toBe('Amount to be refunded')
        ->and($due[2])->not->toContain('-')->not->toContain('−')->toContain('1,190.00')
        ->and($xml)->toContain('<ram:DuePayableAmount>-1190.00</ram:DuePayableAmount>');
});

it('lets an invoice reset configured presentation values explicitly', function () {
    $configured = new Presentation(showGrossPrices: true, footerColumns: [['Configured footer']], paper: 'Letter', labels: ['amount_due' => 'To pay', 'net_total' => 'Net']);
    $own = new Presentation(showGrossPrices: false, footerColumns: [], paper: 'A4', labels: ['net_total' => 'Subtotal'], explicit: ['showGrossPrices', 'footerColumns', 'paper']);

    $merged = ViewModelFactory::mergePresentation($own, $configured);
    $implicit = ViewModelFactory::mergePresentation(new Presentation(paper: 'A4'), $configured);
    $pdf = (string) Quittio::create(['presentation' => $configured])->make(Invoices::simple()->presentation($own))->format('pdf')->withoutValidation()->render()->pdf();
    $html = (string) Quittio::create(['presentation' => $configured])->make(Invoices::simple()->presentation($own))->format('html')->withoutValidation()->render()->html();

    expect([$merged->showGrossPrices, $merged->footerColumns, $merged->paper, $merged->labels])->toBe([false, [], 'A4', ['amount_due' => 'To pay', 'net_total' => 'Subtotal']])
        ->and([$implicit->showGrossPrices, $implicit->paper])->toBe([true, 'Letter'])
        ->and(preg_match('#/MediaBox\s*\[\s*0 0 595\.280 841\.890#', $pdf))->toBe(1)
        ->and($html)->not->toContain('Configured footer')->toContain('Subtotal');
});

it('refuses logo strings that mPDF could turn into a request', function (string $logo) {
    (new LogoCache(sys_get_temp_dir().'/quittio-logo-'.bin2hex(random_bytes(4))))->resolve($logo);
})->with([
    'backslash' => ['\\\\127.0.0.1\\logo.png'],
    'protocol-relative' => ['//127.0.0.1/logo.png'],
    'leading whitespace' => ['   http://example.com/logo.png'],
    'trailing whitespace' => ['https://example.com/logo.png '],
    'data not at start' => ['x/data:image/png;base64,AAAA'],
    'file scheme' => ['file:///etc/passwd'],
])->throws(ConfigurationException::class);

it('throws for a local logo path that does not exist', function () {
    (new LogoCache(sys_get_temp_dir().'/quittio-logo-'.bin2hex(random_bytes(4))))->resolve('/does/not/exist.png');
})->throws(ConfigurationException::class, 'does not exist');

it('refuses data URI SVG logos that reference external resources or declare entities', function (string $svg) {
    (new LogoCache(sys_get_temp_dir().'/quittio-logo-'.bin2hex(random_bytes(4)), pdfa: $this->pdfa ?? false))
        ->resolve('data:image/svg+xml;base64,'.base64_encode($svg));
})->with([
    'external image href' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="10" height="10"><image xlink:href="http://127.0.0.1:8765/x.png"/></svg>'],
    'doctype entity' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY h "xlink:href">]><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><image &h;="http://127.0.0.1:8765/x.png"/></svg>'],
    'entity in cdata' => ['<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><desc><![CDATA[<!ENTITY h "xlink:href">]]></desc><image &h;="http://127.0.0.1:8765/x.png"/></svg>'],
])->throws(ConfigurationException::class, 'references external resources or declares entities');

it('refuses SVG entity declarations also without PDF/A', function () {
    $svg = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY h "x">]><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';

    expect(fn () => (new LogoCache(sys_get_temp_dir().'/l-'.bin2hex(random_bytes(4)), pdfa: false))->resolve('data:image/svg+xml;base64,'.base64_encode($svg)))
        ->toThrow(ConfigurationException::class, 'entities');
});

it('refuses data URIs that the strict decoder cannot read', function (string $logo) {
    (new LogoCache(sys_get_temp_dir().'/quittio-logo-'.bin2hex(random_bytes(4))))->resolve($logo);
})->with([
    // Lenient decoders (mPDF) would skip the "*"; the strict decoder refuses the payload.
    'corrupt base64' => ['data:image/svg+xml;base64,PHN2*ZyB4bWxucz0i'],
])->throws(ConfigurationException::class, 'could not be decoded');

it('re-encodes a valid data URI canonically from the checked bytes', function () {
    $png = (function (): string {
        ob_start();
        imagepng(imagecreatetruecolor(20, 10));

        return (string) ob_get_clean();
    })();
    $cache = new LogoCache(sys_get_temp_dir().'/quittio-logo-'.bin2hex(random_bytes(4)));

    // Uppercase scheme and a charset parameter are normalised; the output is clean base64.
    $upper = (string) $cache->resolve('DATA:image/png;base64,'.base64_encode($png));
    $charset = (string) $cache->resolve('data:image/png;charset=binary;base64,'.base64_encode($png));

    expect($upper)->toBe('data:image/png;base64,'.base64_encode($png))
        ->and($charset)->toBe($upper);
})->skip(! extension_loaded('gd'), 'GD is not available.');

it('stops mPDF from fetching remote resources referenced in the template HTML', function () {
    $html = '<html><body><img src="http://127.0.0.1:8765/should-not-be-fetched.png"><p>x</p></body></html>';

    $pdf = (new MpdfRenderer)->render(new RenderRequest(html: $html, title: 'Deny'));

    expect($pdf)->toStartWith('%PDF');
});
