<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\DeclaredAmountsMode;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\TotalsCalculator;
use Quittio\Core\Countries\ResolvedCountries;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Party\BuyerParty;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Formats\FormatChoice;
use Quittio\Core\Formats\FormatResolver;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcasterChain;
use Quittio\Core\Serialization\Snapshots\SnapshotVersionException;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Laravel\Facades\Quittio;
use Quittio\Laravel\Jobs\RenderInvoice;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Seller;
use Quittio\Laravel\Support\ConfigMapper;
use Quittio\Laravel\Support\Settings;

it('accepts the due days as a numeric string and rejects other values', function () {
    $config = static fn (mixed $days) => ConfigMapper::toCore(new Settings(['defaults' => ['due_days' => $days]]), false);

    expect($config('30')->dueDays)->toBe(30)
        ->and($config(14)->dueDays)->toBe(14)
        ->and($config(null)->dueDays)->toBeNull()
        ->and(fn () => $config('soon'))->toThrow(ConfigurationException::class, 'due_days');
});

it('keeps the default checks when the published file has no validation levels', function () {
    $levels = static fn (array $validation): array => ConfigMapper::toCore(new Settings(['validation' => $validation]), false)->validationLevels;

    expect($levels(['mode' => 'error']))->toBe([ValidationLevel::L1, ValidationLevel::L2, ValidationLevel::L3, ValidationLevel::L7, ValidationLevel::L4])
        ->and($levels(['levels' => ['php']]))->toBe([ValidationLevel::L1, ValidationLevel::L2, ValidationLevel::L3, ValidationLevel::L7])
        // An empty list switches them off on purpose.
        ->and($levels(['levels' => []]))->toBe([]);
});

it('accepts "auto" as the automatic format choice', function () {
    expect(ConfigMapper::toCore(new Settings(['defaults' => ['format' => 'auto']]), false)->format)->toBeNull()
        ->and(ConfigMapper::toCore(new Settings(['defaults' => ['format' => 'cii']]), false)->format)->toBe('cii');

    config()->set('quittio.defaults.format', 'auto');
    Quittio::flush();

    expect(Quittio::make(Scenarios::simple()->build())->render()->format())->toBe('zugferd');
});

it('chooses Peppol with the configured lookup', function () {
    $lookup = new class
    {
        /** @var list<string> */
        public array $buyers = [];

        public function __invoke(BuyerParty $buyer): bool
        {
            $this->buyers[] = $buyer->name;

            return $buyer->address->countryCode === 'IT';
        }
    };
    app()->instance($lookup::class, $lookup);
    $crossBorder = Scenarios::make('intra-eu')->build();

    expect(Quittio::make($crossBorder)->prepare()->format())->toBe('zugferd');

    config()->set('quittio.format_resolver.peppol_lookup', $lookup::class);
    Quittio::flush();

    expect(Quittio::make($crossBorder)->prepare()->format())->toBe('peppol')
        ->and($lookup->buyers)->toBe(['Cliente S.r.l.'])
        // Domestic B2B asks the lookup as well (not reachable here: ZUGFeRD) ...
        ->and(Quittio::make(Scenarios::simple()->build())->prepare()->format())->toBe('zugferd')
        ->and($lookup->buyers)->toHaveCount(2)
        // ... public buyers are decided by the country rule first (XRechnung, no lookup).
        ->and(Quittio::make(Scenarios::simple()->buyerReference('04011000-12345-03')->build())->prepare()->format())->toBe('xrechnung-cii')
        ->and($lookup->buyers)->toHaveCount(2);

    // A callable set at runtime works as well; other values fail.
    config()->set('quittio.format_resolver.peppol_lookup', static fn (BuyerParty $buyer): bool => false);
    Quittio::flush();
    expect(Quittio::make($crossBorder)->prepare()->format())->toBe('zugferd');

    config()->set('quittio.format_resolver.peppol_lookup', stdClass::class);
    Quittio::flush();
    expect(fn () => Quittio::core())->toThrow(ConfigurationException::class, 'peppol_lookup');
});

it('keeps registered format resolvers working', function () {
    $received = null;
    Quittio::extend('format_resolver', 'always-cii', function ($app, array $config) use (&$received): FormatResolver {
        $received = $config;

        return new class implements FormatResolver
        {
            public function resolve(InvoiceData $invoice, ResolvedCountries $countries): FormatChoice
            {
                return new FormatChoice('cii');
            }
        };
    });
    config()->set('quittio.format_resolver.driver', 'always-cii');
    config()->set('quittio.format_resolver.peppol_lookup', 'App\\PeppolLookup');
    Quittio::flush();

    expect(Quittio::make(Scenarios::simple()->build())->prepare()->format())->toBe('cii')
        ->and($received['peppol_lookup'] ?? null)->toBe('App\\PeppolLookup');
});

it('rejects unknown payment QR code types', function () {
    ConfigMapper::presentation(new Settings(['pdf' => ['payment_qr' => ['type' => 'giro-code']]]));
})->throws(ConfigurationException::class, 'giro-code');

it('maps an explicit paper size, the Markdown option and the veraPDF docker binary', function () {
    $config = ConfigMapper::toCore(new Settings([
        'pdf' => ['paper' => 'A4', 'markdown' => true],
        'validation' => ['levels' => ['verapdf'], 'drivers' => ['verapdf' => ['mode' => 'docker', 'docker' => '/usr/local/bin/docker']]],
    ]), false);

    expect($config->options['paper'])->toBe('A4')
        ->and($config->options['markdown'])->toBeTrue()
        ->and($config->veraPdf['docker'] ?? null)->toBe('/usr/local/bin/docker')
        ->and(ConfigMapper::toCore(new Settings([]), false)->options['paper'])->toBeNull();
});

it('takes seller identifiers and the first bank account with an IBAN from the config', function () {
    config()->set('quittio.seller.vat_id', null);
    config()->set('quittio.seller.tax_number', '12/345/67890');
    config()->set('quittio.seller.identifiers', [['value' => '4000001000017', 'scheme' => '0088']]);
    config()->set('quittio.seller.bank_accounts', [['iban' => null], ['iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'name' => 'Muster GmbH']]);

    $invoice = Seller::apply(InvoiceBuilder::make())->number('RE-1')->issueDate('2026-10-01')->deliveryDate('2026-10-01')
        ->buyer(fn ($b) => $b->name('Kunde AG')->address('Weg 1', '54321', 'Beispielstadt', 'DE'))
        ->line(fn ($l) => $l->name('Beratung')->quantity(1, 'HUR')->netPrice('100.00')->vat(VatCategory::StandardRate, '19'))
        ->build();

    expect($invoice->seller->identifiers[0]->value ?? null)->toBe('4000001000017')
        ->and($invoice->paymentInstructions?->primaryCreditTransfer()?->accountId)->toBe('DE89370400440532013000')
        ->and(Quittio::make($invoice)->format('pdf')->render()->hasPdf())->toBeTrue();
});

it('restores queued snapshots through the upcaster chain', function () {
    Storage::fake('invoices');
    $invoice = Scenarios::make('simple')->build();
    $job = new RenderInvoice(json_encode($invoice->toSnapshot(), JSON_THROW_ON_ERROR), new RenderOptions(formats: ['cii']), 'invoices', 'queued/');
    $future = new RenderInvoice(json_encode(['_version' => InvoiceData::SNAPSHOT_VERSION + 1, ...$invoice->toArray()], JSON_THROW_ON_ERROR), new RenderOptions);

    expect($job->data(new SnapshotUpcasterChain)->number)->toBe($invoice->number)
        // Payloads queued before snapshots were used are plain JSON.
        ->and((new RenderInvoice($invoice->toJson(), new RenderOptions))->data()->number)->toBe($invoice->number)
        ->and(fn () => $future->data(new SnapshotUpcasterChain))->toThrow(SnapshotVersionException::class);

    $job->handle(app(QuittioManager::class), new SnapshotUpcasterChain);
    Storage::disk('invoices')->assertExists('queued/Rechnung_RE-2026-0042.xml');
});

it('dispatches the snapshot form', function () {
    Illuminate\Support\Facades\Queue::fake();
    Quittio::make(Scenarios::make('simple')->build())->format('cii')->queue()->save('invoices');

    Illuminate\Support\Facades\Queue::assertPushed(RenderInvoice::class, fn (RenderInvoice $job): bool => str_starts_with($job->invoice, '{"_version":'.InvoiceData::SNAPSHOT_VERSION));
});

it('reads the environment variables and ignores the names of 0.1.0', function () {
    $variables = ['QUITTIO_DEFAULT_COUNTRY', 'QUITTIO_COUNTRY', 'QUITTIO_VALIDATION_MODE', 'QUITTIO_VALIDATION', 'QUITTIO_MANDATORY_FIELD_GUARD', 'QUITTIO_GUARD', 'QUITTIO_STORAGE_DISK', 'QUITTIO_DISK', 'QUITTIO_PREVIEW_ENABLED', 'QUITTIO_PREVIEW'];
    $config = static fn (): array => require dirname(__DIR__, 2).'/config/quittio.php';

    try {
        foreach (['QUITTIO_COUNTRY=AT', 'QUITTIO_VALIDATION=warn', 'QUITTIO_GUARD=log', 'QUITTIO_DISK=s3', 'QUITTIO_PREVIEW=false'] as $assignment) {
            putenv($assignment);
        }
        $old = $config();
        foreach (['QUITTIO_DEFAULT_COUNTRY=CH', 'QUITTIO_VALIDATION_MODE=off', 'QUITTIO_MANDATORY_FIELD_GUARD=off', 'QUITTIO_STORAGE_DISK=public', 'QUITTIO_PREVIEW_ENABLED=true'] as $assignment) {
            putenv($assignment);
        }
        $new = $config();
    } finally {
        foreach ($variables as $variable) {
            putenv($variable);
        }
    }

    expect([$old['defaults']['country'], $old['validation']['mode'], $old['validation']['mandatory_field_guard'], $old['storage']['disk'], $old['preview']['enabled']])->toBe([null, 'error', null, 'local', null])
        ->and([$new['defaults']['country'], $new['validation']['mode'], $new['validation']['mandatory_field_guard'], $new['storage']['disk'], $new['preview']['enabled']])->toBe(['CH', 'off', 'off', 'public', true]);
});

it('maps the rule overrides and refuses unknown rule codes', function () {
    config()->set('quittio.validation.rules', ['disabled' => ['DE-USTG-15'], 'severity' => ['DE-USTG-08' => 'warning']]);
    $driver = Quittio::core()->validators()[0];

    expect($driver)->toBeInstanceOf(PhpRulesDriver::class)
        ->and($driver instanceof PhpRulesDriver ? $driver->overrides()->disabled : null)->toBe(['DE-USTG-15'])
        ->and($driver instanceof PhpRulesDriver ? $driver->overrides()->severities : null)->toBe(['DE-USTG-08' => Severity::Warning]);

    config()->set('quittio.validation.rules.disabled', ['DE-USTG-99']);
    expect(fn () => Quittio::core())->toThrow(ConfigurationException::class, 'DE-USTG-99');
});

it('replaces a rule with an extension of type rule', function () {
    $rule = CallbackRule::l7('DE-USTG-15', 'Own check', fn () => null, 'quittio-de');
    Quittio::extend('rule', 'DE-USTG-15', fn () => $rule);
    $driver = Quittio::core()->validators()[0];

    expect($driver instanceof PhpRulesDriver ? $driver->overrides()->replacements : null)->toBe(['DE-USTG-15' => $rule])
        ->and(Quittio::core()->ruleCodes())->toContain('DE-USTG-15');
});

it('maps the calculation policy and an own calculator', function () {
    expect(ConfigMapper::toCore(new Settings(['calculation' => ['declared_amounts' => 'reject']]), false)->calculation->declaredAmounts)->toBe(DeclaredAmountsMode::Reject);

    app()->instance(CountingCalculator::class, new CountingCalculator);
    config()->set('quittio.calculation.calculator', CountingCalculator::class);
    Quittio::edit((new En16931Calculator)->calculate(Scenarios::simple()->build()))->build();

    expect(Quittio::core()->calculator())->toBe(app(CountingCalculator::class))
        ->and(app(CountingCalculator::class)->calls)->toBeGreaterThan(0);

    config()->set('quittio.calculation.calculator', stdClass::class);
    expect(fn () => Quittio::core())->toThrow(ConfigurationException::class, 'TotalsCalculator');
});

final class CountingCalculator implements TotalsCalculator
{
    public int $calls = 0;

    public function calculate(InvoiceData $invoice): InvoiceData
    {
        $this->calls++;

        return (new En16931Calculator)->calculate($invoice);
    }
}
