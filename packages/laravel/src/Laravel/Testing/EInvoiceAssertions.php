<?php

declare(strict_types=1);

namespace Quittio\Laravel\Testing;

/**
 * The assertions of {@see InteractsWithQuittio} outside a test case class, e.g. in Pest
 * helper functions: (new EInvoiceAssertions)->assertValidEInvoice($pdf, 'xrechnung').
 */
final class EInvoiceAssertions
{
    use InteractsWithQuittio;

    public function __construct(
        private readonly string $snapshotDirectory = '',
    ) {}

    protected function quittioSnapshotDirectory(): string
    {
        return $this->snapshotDirectory === '' ? getcwd().'/tests/__snapshots__/quittio' : $this->snapshotDirectory;
    }

    protected function quittioSnapshotName(): string
    {
        return 'xml';
    }
}
