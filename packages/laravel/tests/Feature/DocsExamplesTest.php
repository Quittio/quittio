<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Rendering\RenderedInvoice;

// Runs the code of docs/examples.md, so that the examples keep working. Render runs validate in
// mode "error", so an example that builds an invalid invoice fails here, and every rendered
// invoice must come without warnings. The code runs in a namespace of its own, in which the test
// provides what the page takes from elsewhere: the mailable, the tables and an order factory.

const DOCS_EXAMPLES = 'Quittio\Tests\DocsExamples';

/**
 * The PHP code blocks of docs/examples.md by heading, each with the plain code block that
 * follows it (the printed output), if any. Empty outside the monorepo.
 *
 * @return array<string, list<array{code: string, output: ?string}>>
 */
function docsExamples(): array
{
    $file = dirname(__DIR__, 4).'/docs/examples.md';
    if (! is_file($file)) {
        return [];
    }

    preg_match_all('/^(#{1,3}) (.+?)$|^```(\w*)\n(.*?)^```$/ms', (string) file_get_contents($file), $matches, PREG_SET_ORDER);
    $examples = [];
    $heading = '';
    foreach ($matches as $match) {
        if ($match[1] !== '') {
            $heading = $match[2];
        } elseif ($match[3] === 'php') {
            $examples[$heading][] = ['code' => $match[4], 'output' => null];
        } elseif ($match[3] === '' && isset($examples[$heading])) {
            $examples[$heading][array_key_last($examples[$heading])]['output'] ??= $match[4];
        }
    }

    return $examples;
}

/**
 * Runs code of the page in the namespace of the examples and returns its variables
 * (`__return` is the value the code returns, `__output` what it printed).
 *
 * @param  array<string, mixed>  $variables
 * @return array<string, mixed>
 */
function runDocsExample(string $code, array $variables = []): array
{
    // The script of "Without Laravel" starts with "<?php" and loads the autoloader.
    $code = (string) preg_replace(['/\A<\?php\n/', '/^require .*autoload\.php\';\n/m'], '', $code);
    $directory = sys_get_temp_dir().'/quittio-docs-examples-'.getmypid();
    File::ensureDirectoryExists($directory);
    $file = $directory.'/example.php';
    file_put_contents($file, "<?php\n\nnamespace ".DOCS_EXAMPLES.";\n\n".$code);

    try {
        return (static function (string $__file, array $__variables): array {
            extract($__variables);
            unset($__variables);
            ob_start();
            try {
                $__return = require $__file;
            } finally {
                $__output = (string) ob_get_clean();
            }

            return get_defined_vars();
        })($file, $variables);
    } finally {
        File::deleteDirectory($directory);
    }
}

/**
 * Runs all code blocks of one heading in order and checks their printed output.
 *
 * @param  array<string, mixed>  $variables
 * @return array<string, mixed>
 */
function runDocsExampleSection(string $heading, array $variables = []): array
{
    expect(docsExamples())->toHaveKey($heading);
    foreach (docsExamples()[$heading] as $block) {
        $variables = runDocsExample($block['code'], $variables);
        if ($block['output'] !== null) {
            expect($variables['__output'])->toBe($block['output'], $heading);
        }
    }

    return $variables;
}

/**
 * Declares the models and the mailable of the examples (once per process) and creates the tables.
 */
function docsExampleModels(): void
{
    if (! class_exists(DOCS_EXAMPLES.'\Order', false)) {
        runDocsExample(docsExamples()['Invoices for orders'][0]['code']);
        runDocsExample(<<<'PHP'
            use Illuminate\Mail\Mailable;
            use Illuminate\Mail\Mailables\Content;
            use Illuminate\Mail\Mailables\Envelope;
            use Quittio\Laravel\Rendering\RenderedInvoice;

            final class InvoiceMail extends Mailable
            {
                public function __construct(private RenderedInvoice $invoice) {}

                public function envelope(): Envelope
                {
                    return new Envelope(subject: 'Invoice '.$this->invoice->data()->number);
                }

                public function content(): Content
                {
                    return new Content(htmlString: '<p>Your invoice</p>');
                }

                public function attachments(): array
                {
                    return [$this->invoice];
                }
            }
            PHP);
    }

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('company');
        $table->string('vat_id')->nullable();
        $table->string('street');
        $table->string('postcode');
        $table->string('city');
        $table->string('country', 2);
        $table->string('email');
        $table->timestamps();
    });
    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->foreignId('customer_id');
        $table->string('invoice_number');
        $table->date('invoiced_at');
        $table->date('shipped_at');
        $table->string('customer_reference')->nullable();
        $table->string('invoice_path')->nullable();
        $table->timestamps();
    });
    Schema::create('order_items', function (Blueprint $table) {
        $table->id();
        $table->foreignId('order_id');
        $table->string('product_name');
        $table->string('sku');
        $table->integer('quantity');
        $table->decimal('unit_price', 10, 2);
        $table->string('tax_preset');
        $table->timestamps();
    });
}

/**
 * The order factory of the testing example.
 *
 * @param  array<string, mixed>  $attributes
 */
function docsExampleOrder(array $attributes = []): Model
{
    return Model::unguarded(function () use ($attributes): Model {
        $customer = (DOCS_EXAMPLES.'\Customer')::create([
            'company' => 'Kunde AG', 'vat_id' => 'DE987654321', 'street' => 'Bahnhofstraße 5',
            'postcode' => '54321', 'city' => 'Beispielstadt', 'country' => 'DE', 'email' => 'ap@kunde.de',
        ]);
        $order = (DOCS_EXAMPLES.'\Order')::create($attributes + [
            'customer_id' => $customer->id, 'invoice_number' => 'RE-2026-0130',
            'invoiced_at' => '2026-10-13', 'shipped_at' => '2026-10-12', 'customer_reference' => 'PO-77',
        ]);
        $order->items()->createMany([
            ['product_name' => 'Desk organiser', 'sku' => 'DO-1', 'quantity' => 3, 'unit_price' => '24.90', 'tax_preset' => 'de.standard_19'],
            ['product_name' => 'Handbook of VAT law', 'sku' => 'B-7', 'quantity' => 1, 'unit_price' => '89.00', 'tax_preset' => 'de.reduced_7'],
        ]);

        return $order->fresh(['customer', 'items']);
    });
}

beforeEach(function () {
    if (docsExamples() === []) {
        $this->markTestSkipped('docs/examples.md exists only in the monorepo.');
    }

    // The seller configuration at the top of the page.
    $setup = eval('return ['.docsExamples()['Examples'][0]['code'].'];');
    config()->set('quittio.seller', $setup['seller'] + config('quittio.seller'));
    Quittio::flush();
    Storage::fake('invoices');
    Storage::fake('documents');
});

it('runs the example', function (string $heading) {
    $variables = match ($heading) {
        'Cancellation', 'Logo, colours and texts' => runDocsExampleSection('Service invoice'),
        'Incoming e-invoices' => ['request' => Request::create('/', 'POST', files: [
            'invoice' => UploadedFile::fake()->createWithContent('invoice.pdf', runDocsExampleSection('Service invoice')['result']->pdf()),
        ])],
        default => [],
    };
    $logo = storage_path('app/logo.png');
    if ($heading === 'Logo, colours and texts') {
        File::ensureDirectoryExists(dirname($logo));
        $image = imagecreatetruecolor(300, 100);
        imagepng($image, $logo);
    }

    try {
        $variables = runDocsExampleSection($heading, $variables);
    } finally {
        File::delete($logo);
    }

    foreach ($variables as $name => $value) {
        if ($value instanceof RenderedInvoice) {
            expect($value->validation()->messages)->toBe([], "{$heading}: \${$name}");
        }
    }
})->with(fn () => array_values(array_diff(array_keys(docsExamples()), ['Examples', 'Invoices for orders', 'Testing'])) ?: ['']);

it('runs the order example', function () {
    Mail::fake();
    docsExampleModels();

    $variables = runDocsExample(docsExamples()['Invoices for orders'][1]['code'], ['order' => docsExampleOrder()]);

    expect($variables['result']->validation()->messages)->toBe([])
        ->and($variables['__return']->getStatusCode())->toBe(200);
    Storage::disk('invoices')->assertExists('orders/Rechnung_RE-2026-0130.pdf');
    Mail::assertSent(DOCS_EXAMPLES.'\InvoiceMail');
});

it('runs the testing example', function () {
    docsExampleModels();
    Route::post('/orders/{id}/ship', fn (string $id) => runDocsExample(docsExamples()['Invoices for orders'][1]['code'], [
        'order' => (DOCS_EXAMPLES.'\Order')::with('customer', 'items')->findOrFail($id),
    ])['__return']);

    // The tests of the page become closures, and the application's factory a helper of this file.
    $code = (string) preg_replace(['/^it\((.+), function \(\) \{$/m', '/^\}\);$/m'], ['$tests[$1] = function () {', '};'], docsExamples()['Testing'][0]['code']);
    $tests = runDocsExample(str_replace('Order::factory()->create(', '\docsExampleOrder(', $code))['tests'];

    expect($tests)->toHaveCount(2);
    foreach ($tests as $test) {
        $test->call($this);
    }
});
