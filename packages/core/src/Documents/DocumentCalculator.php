<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\TotalsCalculator;

/**
 * Calculates documents with the invoice calculator. Optional and alternative lines get
 * their line amounts but are excluded from the totals.
 */
final class DocumentCalculator
{
    public function __construct(
        private readonly TotalsCalculator $calculator = new En16931Calculator,
    ) {}

    /**
     * Returns $document with the line amounts of all lines, and totals, VAT breakdown, allowances
     * and charges of the billable lines only (DocumentData::billableLines()). A document without
     * lines is returned unchanged.
     */
    public function calculate(DocumentData $document): DocumentData
    {
        if ($document->lines === []) {
            return $document;
        }

        $allLines = $this->calculator->calculate($document->toInvoiceDraft(billableOnly: false)->with(['allowances' => [], 'charges' => []]));
        $changes = ['lines' => $allLines->lines];

        if ($document->billableLines() !== []) {
            $billable = $this->calculator->calculate($document->toInvoiceDraft()->with(['totals' => null, 'vatBreakdown' => []]));
            $changes['totals'] = $billable->totals;
            $changes['vatBreakdown'] = $billable->vatBreakdown;
            $changes['allowances'] = $billable->allowances;
            $changes['charges'] = $billable->charges;
        }

        return $document->with($changes);
    }
}
