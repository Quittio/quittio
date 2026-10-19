<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal\Sections;

use Brick\Money\Money;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Support\Amount;
use Quittio\Core\View\Internal\RunScope;
use Quittio\Core\View\ViewModelFactory;

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
     * One row per VAT breakdown row; an exempt group that mixes a special scheme with other
     * exemptions (ViewModelFactory::mixedExemptGroup()) shows both parts in rows of their own, or
     * in one row without rate and VAT amount when their amounts cannot be told apart.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $scope = $this->scope;
        $labels = $scope->baseLabels;
        $locale = $scope->locale;
        $mixed = ViewModelFactory::mixedExemptGroup($scope->invoice);
        $currency = $scope->invoice->currency;

        $rows = [];
        foreach ($scope->invoice->vatBreakdown as $row) {
            if ($mixed !== null && $row->category === VatCategory::Exempt) {
                if ($mixed['special'] === null || $mixed['other'] === null) {
                    $rows[] = $this->row($row, $labels['vat_row_exempt_special_scheme'] ?? $labels['vat_row_special_scheme'], special: true);
                } else {
                    $rows[] = $this->row($row, $labels['vat_row_special_scheme'], special: true, base: Amount::of($mixed['special'], $currency));
                    $rows[] = $this->row($row, $labels['vat_row_exempt'], special: false, base: Amount::of($mixed['other'], $currency));
                }

                continue;
            }
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
            $rows[] = $this->row($row, $template, $special);
        }

        return $rows;
    }

    /**
     * A row of the view model; $base replaces the taxable amount of the row for a part of it.
     *
     * @return array<string, mixed>
     */
    private function row(VatBreakdown $row, string $template, bool $special, ?Money $base = null): array
    {
        $scope = $this->scope;
        $locale = $scope->locale;
        $formattedBase = $scope->money($base ?? $row->taxableAmount);
        $rate = $row->rate === null || $special ? null : $scope->percent($row->rate);
        $label = str_replace([':category', ':rate', ':base'], [$row->category->label($locale), (string) $rate, (string) $formattedBase], $template);

        return [
            'label' => $label,
            'category' => $row->category->value,
            'categoryLabel' => $row->category->label($locale),
            'rate' => $row->rate === null ? null : $scope->percent($row->rate),
            'base' => $formattedBase,
            'amount' => $special ? null : $scope->money($row->taxAmount),
            'isSpecialScheme' => $special,
            'exemptionReason' => $row->exemptionReason,
            'exemptionReasonCode' => $row->exemptionReasonCode?->value,
        ];
    }
}
