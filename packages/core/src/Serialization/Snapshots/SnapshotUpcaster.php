<?php

declare(strict_types=1);

namespace Quittio\Core\Serialization\Snapshots;

/**
 * Migrates a stored invoice snapshot from one version to the next one.
 *
 * Snapshots of issued invoices are never rewritten; upcasters run in memory on read.
 */
interface SnapshotUpcaster
{
    /**
     * Version this upcaster reads; it produces version from() + 1.
     */
    public function from(): int;

    /**
     * @param  array<string, mixed>  $snapshot  without the "_version" key
     * @return array<string, mixed>
     */
    public function upcast(array $snapshot): array;
}
