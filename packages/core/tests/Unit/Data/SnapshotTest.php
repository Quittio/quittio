<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcaster;
use Quittio\Core\Serialization\Snapshots\SnapshotUpcasterChain;
use Quittio\Core\Serialization\Snapshots\SnapshotVersionException;
use Quittio\Core\Testing\Scenarios;

function snapshotFixtures(): array
{
    return glob(dirname(__DIR__, 2).'/Fixtures/snapshots/v*/*.json') ?: [];
}

/**
 * Upcaster for tests: renames a top-level key.
 */
function renamingUpcaster(int $from, string $old, string $new): SnapshotUpcaster
{
    return new class($from, $old, $new) implements SnapshotUpcaster
    {
        public function __construct(private int $version, private string $old, private string $new) {}

        public function from(): int
        {
            return $this->version;
        }

        public function upcast(array $snapshot): array
        {
            if (array_key_exists($this->old, $snapshot)) {
                $snapshot[$this->new] = $snapshot[$this->old];
                unset($snapshot[$this->old]);
            }

            return $snapshot;
        }
    };
}

it('restores a snapshot of the current version', function () {
    $invoice = (new En16931Calculator)->calculate(Scenarios::make('mixed-vat')->build());

    expect(InvoiceData::fromSnapshot($invoice->toSnapshot())->toSnapshot())->toBe($invoice->toSnapshot());
});

it('reads every stored fixture version up to the current one', function (string $file) {
    $snapshot = json_decode((string) file_get_contents($file), true);

    expect(InvoiceData::fromSnapshot($snapshot)->number)->not->toBe('');
})->with(snapshotFixtures());

it('keeps one fixture directory per snapshot version', function () {
    // Raising SNAPSHOT_VERSION requires fixtures of the new version and an upcaster.
    $directories = glob(dirname(__DIR__, 2).'/Fixtures/snapshots/v*', GLOB_ONLYDIR) ?: [];

    expect(count($directories))->toBe(InvoiceData::SNAPSHOT_VERSION);
});

it('treats snapshots without version as version 1', function () {
    $snapshot = (new En16931Calculator)->calculate(Scenarios::make('simple')->build())->toSnapshot();
    unset($snapshot['_version']);

    expect(InvoiceData::fromSnapshot($snapshot)->number)->toBe('RE-2026-0042');
});

it('runs upcasters in order up to the target version', function () {
    $chain = new SnapshotUpcasterChain([
        renamingUpcaster(2, 'b', 'c'),
        renamingUpcaster(1, 'a', 'b'),
    ]);

    expect($chain->upcast(['a' => 1], 1, 3))->toBe(['c' => 1])
        ->and($chain->upcast(['a' => 1], 1, 2))->toBe(['b' => 1])
        ->and($chain->upcast(['x' => 1], 3, 3))->toBe(['x' => 1]);
});

it('fails on missing upcasters and on newer snapshots', function (int $from, int $to, string $message) {
    expect(fn () => (new SnapshotUpcasterChain([renamingUpcaster(1, 'a', 'b')]))->upcast([], $from, $to))
        ->toThrow(SnapshotVersionException::class, $message);
})->with([
    'gap' => [1, 3, 'No upcaster registered for snapshot version 2'],
    'newer' => [4, 3, 'Update the package'],
    'invalid' => [0, 1, 'Invalid snapshot version'],
]);

it('uses a registered default chain', function () {
    $snapshot = (new En16931Calculator)->calculate(Scenarios::make('simple')->build())->toSnapshot();
    $snapshot['_version'] = 0;

    expect(fn () => InvoiceData::fromSnapshot($snapshot))->toThrow(SnapshotVersionException::class);
});

it('restores InvoiceData only through promoted constructor properties', function () {
    // Immutable::with() rebuilds objects from get_object_vars(); additional properties would be lost silently.
    $reflection = new ReflectionClass(InvoiceData::class);
    $promoted = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $reflection->getConstructor()?->getParameters() ?? []);
    $properties = array_map(static fn (ReflectionProperty $p): string => $p->getName(), array_filter($reflection->getProperties(), static fn (ReflectionProperty $p): bool => ! $p->isStatic()));

    expect($properties)->toBe($promoted);
});
