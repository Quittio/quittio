<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal\Sections;

use Quittio\Core\Enums\VatCategory;
use Quittio\Core\View\Internal\RunScope;

/**
 * The VAT breakdown (BG-23) with a ready label per row.
 *
 * @internal
 */
final readonly class VatBreakdownSection
{
    public function __construct(private RunScope $scope) {}

    /**
     * @return array<string, mixed> vatRows
     */
    public function build(): array
    {
        return ['vatRows' => $this->rows()];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $scope = $this->scope;
        $labels = $scope->baseLabels;
        $locale = $scope->locale;

        return array_map(function ($row) use ($scope, $labels, $locale): array {
            $base = $scope->money($row->taxableAmount);
            $rate = $row->rate === null ? null : $scope->percent($row->rate);
            // Margin schemes and travel agents are taxed on the margin: no "exempt", no VAT amount
            // (section 14a (6) sentence 2 UStG forbids a separate VAT statement).
            $special = $scope->isSpecialScheme($row->category);
            // Reverse charge, zero rate and "not subject to VAT" are no tax exemptions and are named as such.
            // IGIC (Canary Islands) and IPSI (Ceuta, Melilla) are taxes of their own, not VAT.
            $template = match (true) {
                $special => $labels['vat_row_special_scheme'],
                $row->category === VatCategory::CanaryIslands => $labels['vat_row_igic'],
                $row->category === VatCategory::CeutaMelilla => $labels['vat_row_ipsi'],
                $row->category === VatCategory::ReverseCharge => $labels['vat_row_reverse_charge'],
                $row->category === VatCategory::ZeroRated => $labels['vat_row_zero_rated'],
                $row->category === VatCategory::NotSubjectToVat => $labels['vat_row_not_subject'],
                default => $row->category->isZeroTax() ? $labels['vat_row_exempt'] : $labels['vat_row'],
            };
            // One VAT rate: its base is the net total printed above, "USt. 19 %" suffices.
            if ($template === $labels['vat_row'] && count($scope->invoice->vatBreakdown) === 1 && isset($labels['vat_row_single'])) {
                $template = $labels['vat_row_single'];
            }
            $label = str_replace([':category', ':rate', ':base'], [$row->category->label($locale), (string) $rate, (string) $base], $template);

            return [
                'label' => $label,
                'category' => $row->category->value,
                'categoryLabel' => $row->category->label($locale),
                'rate' => $rate,
                'base' => $base,
                'amount' => $special ? null : $scope->money($row->taxAmount),
                'isSpecialScheme' => $special,
                'exemptionReason' => $row->exemptionReason,
                'exemptionReasonCode' => $row->exemptionReasonCode?->value,
            ];
        }, $scope->invoice->vatBreakdown);
    }
}
