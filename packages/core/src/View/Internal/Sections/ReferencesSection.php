<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal\Sections;

use Quittio\Core\View\Internal\RunScope;

/**
 * Document references (order, contract, project …) and preceding invoices.
 *
 * @internal
 */
final readonly class ReferencesSection
{
    public function __construct(private RunScope $scope) {}

    /**
     * @return array<string, mixed> references, precedingInvoices
     */
    public function build(): array
    {
        return [
            'references' => $this->references(),
            'precedingInvoices' => array_map(fn ($p): array => [
                'number' => $p->number,
                'issueDate' => $this->scope->date($p->issueDate),
            ], $this->scope->invoice->precedingInvoices),
        ];
    }

    /**
     * @return list<array{key: string, label: string, value: string}>
     */
    private function references(): array
    {
        $i = $this->scope->invoice;
        $labels = $this->scope->baseLabels;
        $references = [
            'buyer_reference' => $i->buyerReference,
            'purchase_order' => $i->purchaseOrderReference,
            'sales_order' => $i->salesOrderReference,
            'contract' => $i->contractReference,
            'project' => $i->projectReference === null ? null : trim($i->projectReference->id.' '.($i->projectReference->name ?? '')),
            'despatch_advice' => $i->despatchAdviceReference,
            'tender' => $i->tenderReference,
            'invoiced_object' => $i->invoicedObject?->value,
            'accounting_reference' => $i->buyerAccountingReference,
        ];

        $result = [];
        foreach ($references as $key => $value) {
            if ($value !== null && $value !== '') {
                $result[] = ['key' => $key, 'label' => $labels[$key] ?? $key, 'value' => $value];
            }
        }

        return $result;
    }
}
