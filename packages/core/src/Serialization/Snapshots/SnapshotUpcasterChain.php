<?php

declare(strict_types=1);

namespace Quittio\Core\Serialization\Snapshots;

use Quittio\Core\Data\InvoiceData;

/**
 * Brings stored snapshots step by step to the current version (v1 → v2 → … → current).
 */
final class SnapshotUpcasterChain
{
    private static ?self $default = null;

    /** @var array<int, SnapshotUpcaster> keyed by the version they read */
    private array $upcasters = [];

    /**
     * @param  iterable<SnapshotUpcaster>  $upcasters
     */
    public function __construct(iterable $upcasters = [])
    {
        foreach ($upcasters as $upcaster) {
            $this->register($upcaster);
        }
    }

    /**
     * Shared instance used by InvoiceData::fromSnapshot().
     */
    public static function default(): self
    {
        return self::$default ??= new self;
    }

    public static function useDefault(?self $chain): void
    {
        self::$default = $chain;
    }

    public function register(SnapshotUpcaster $upcaster): self
    {
        $this->upcasters[$upcaster->from()] = $upcaster;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $snapshot  without the "_version" key
     * @return array<string, mixed>
     */
    public function upcast(array $snapshot, int $from, int $to = InvoiceData::SNAPSHOT_VERSION): array
    {
        if ($from > $to) {
            throw new SnapshotVersionException(sprintf('The snapshot has version %d, but this installation reads up to version %d. Update the package.', $from, $to));
        }
        if ($from < 1) {
            throw new SnapshotVersionException(sprintf('Invalid snapshot version %d.', $from));
        }

        for ($version = $from; $version < $to; $version++) {
            $upcaster = $this->upcasters[$version]
                ?? throw new SnapshotVersionException(sprintf('No upcaster registered for snapshot version %d to %d.', $version, $version + 1));
            $snapshot = $upcaster->upcast($snapshot);
        }

        return $snapshot;
    }
}
