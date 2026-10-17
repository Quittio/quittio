<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal\Sections;

use Quittio\Core\View\Internal\RunScope;

/**
 * Allowances and charges on document level (BG-20, BG-21).
 *
 * @internal
 */
final readonly class AllowancesSection
{
    public function __construct(private RunScope $scope) {}

    /**
     * @return array<string, mixed> allowances, charges
     */
    public function build(): array
    {
        return [
            'allowances' => $this->entries(false),
            'charges' => $this->entries(true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entries(bool $charges): array
    {
        $scope = $this->scope;
        $entries = $charges ? $scope->invoice->charges : $scope->invoice->allowances;
        // With several VAT groups (e.g. a document discount split by AllowanceSplitter) each row names its VAT rate.
        $showVat = count($scope->invoice->vatBreakdown) > 1;

        return array_map(function ($entry) use ($scope, $charges, $showVat): array {
            $reason = $entry->reason ?? $entry->reasonCodeValue();
            $percent = $entry->percent === null ? null : $scope->percent($entry->percent);
            $vatRate = $entry->vatRate === null ? null : $scope->percent($entry->vatRate);
            $vat = match (true) {
                ! $showVat => null,
                $vatRate !== null && ! $entry->vatCategory->isZeroTax() => str_replace(':rate', $vatRate->formatted, $scope->labels['allowance_vat_rate'] ?? ':rate'),
                default => $entry->vatCategory->label($scope->locale),
            };

            return [
                'reason' => $reason,
                'amount' => $scope->money($entry->amount),
                'percent' => $percent,
                'base' => $scope->money($entry->baseAmount),
                'vatRate' => $vatRate,
                'vatCategory' => $entry->vatCategory->value,
                'label' => RunScope::join([$scope->labels[$charges ? 'charge' : 'allowance'] ?? '', $reason, $percent?->formatted, $vat === null ? null : '('.$vat.')']),
            ];
        }, $entries);
    }
}
