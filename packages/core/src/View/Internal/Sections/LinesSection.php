<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal\Sections;

use Brick\Math\BigDecimal;
use Quittio\Core\Data\AllowanceCharge\LineAllowanceCharge;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Support\Amount;
use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\Internal\RunScope;

/**
 * The lines, also grouped for display with subtotals and carry-over amounts.
 *
 * @internal
 */
final readonly class LinesSection
{
    public function __construct(private RunScope $scope) {}

    /**
     * @return array<string, mixed> lineGroups, lines
     */
    public function build(): array
    {
        return [
            'lineGroups' => $this->lineGroups(),
            'lines' => array_map(fn (InvoiceLine $l): array => $this->line($l), $this->scope->invoice->lines),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(InvoiceLine $line): array
    {
        $scope = $this->scope;
        $currency = $scope->invoice->currency;
        $f = $scope->context->formatter;
        $locale = $scope->locale;
        $showGross = $line->presentation->showGrossPrice ?? $scope->presentation->showGrossPrices;

        return [
            'position' => $line->id,
            'id' => $line->id,
            'name' => $line->item->name,
            'description' => [
                'text' => $line->item->description,
                'html' => $scope->textHtml($line->item->description),
            ],
            'note' => $line->note,
            'sellerId' => $line->item->sellerId,
            'buyerId' => $line->item->buyerId,
            'quantity' => new FormattedValue($f->quantity($line->quantity, $locale), $line->quantity),
            'unit' => [
                'code' => $line->unit->value,
                'label' => $line->unit instanceof UnitCode ? $line->unit->label($locale) : $line->unit->value,
            ],
            'unitPrice' => $line->price->netPrice === null ? null : new FormattedValue($f->price($line->price->netPrice, $currency, $locale), $line->price->netPrice),
            'grossUnitPrice' => $line->price->priceInclVat === null ? null : new FormattedValue($f->price($line->price->priceInclVat, $currency, $locale), $line->price->priceInclVat),
            'listPrice' => $line->price->grossPrice === null ? null : new FormattedValue($f->price($line->price->grossPrice, $currency, $locale), $line->price->grossPrice),
            'baseQuantity' => $line->price->baseQuantity === null ? null : new FormattedValue($f->quantity($line->price->baseQuantity, $locale), $line->price->baseQuantity),
            // Price base quantity (BT-149) with unit, e.g. "100 Stk." for a price per 100 pieces; null for 1.
            'unitPriceBase' => $this->priceBase($line),
            'showGrossPrice' => $showGross && $line->price->priceInclVat !== null,
            'total' => $scope->money($line->netAmount),
            // Margin schemes and travel agents: no rate either (no separate VAT statement).
            'vatRate' => $line->vat->rate === null || $scope->isSpecialSchemeLine($line) ? null : $scope->percent($line->vat->rate),
            'vatCategory' => $line->vat->category->value,
            'period' => $line->period === null ? null : $scope->period($line->period),
            'attributes' => array_map(static fn ($a): array => ['name' => $a->name, 'value' => $a->value], $line->item->attributes),
            'allowances' => array_map(fn ($a): array => $this->lineAllowanceCharge($a), $line->allowances),
            'charges' => array_map(fn ($c): array => $this->lineAllowanceCharge($c), $line->charges),
            'subLines' => array_map(fn (InvoiceLine $sub): array => $this->line($sub), $line->subLines),
            'highlight' => $line->presentation->highlight ?? false,
            'groupId' => $line->presentation?->groupId,
            'optional' => $scope->document?->options($line->id)->optional ?? false,
            'alternativeTo' => $scope->document?->options($line->id)->alternativeTo,
            'serialNumbers' => $scope->document?->options($line->id)->serialNumbers ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineAllowanceCharge(LineAllowanceCharge $entry): array
    {
        $reason = $entry->reason ?? ($entry->reasonCode === null ? null : $entry->reasonCodeValue());
        $percent = $entry->percent === null ? null : $this->scope->percent($entry->percent);

        return [
            'reason' => $reason,
            'amount' => $this->scope->money($entry->amount),
            'percent' => $percent,
            'label' => RunScope::join([$this->scope->labels[$entry->isCharge() ? 'charge' : 'allowance'] ?? '', $reason, $percent?->formatted]),
        ];
    }

    private function priceBase(InvoiceLine $line): ?FormattedValue
    {
        $base = $line->price->baseQuantity;
        if ($base === null || $base->isEqualTo(1)) {
            return null;
        }

        $unit = $line->price->baseQuantityUnit ?? $line->unit;
        $label = $unit instanceof UnitCode ? $unit->label($this->scope->locale) : $unit->value;

        return new FormattedValue(trim($this->scope->context->formatter->quantity($base, $this->scope->locale).' '.$label), $base);
    }

    /**
     * Lines grouped for display, with optional subtotals.
     *
     * @return list<array<string, mixed>>
     */
    private function lineGroups(): array
    {
        $invoice = $this->scope->invoice;
        $groups = [];
        foreach ($this->scope->presentation->lineGroups as $group) {
            $groups[$group->id] = ['id' => $group->id, 'title' => $group->title, 'showSubtotal' => $group->showSubtotal, 'lines' => [], 'sum' => BigDecimal::zero()];
        }
        $ungrouped = ['id' => null, 'title' => null, 'showSubtotal' => false, 'lines' => [], 'sum' => BigDecimal::zero()];

        foreach ($invoice->lines as $line) {
            $id = $line->presentation?->groupId;
            $view = $this->line($line);
            $amount = $line->netAmount?->getAmount() ?? BigDecimal::zero();
            $view['amount'] = $amount;
            if ($id !== null && isset($groups[$id])) {
                $groups[$id]['lines'][] = $view;
                $groups[$id]['sum'] = $groups[$id]['sum']->plus($amount);
            } else {
                $ungrouped['lines'][] = $view;
                $ungrouped['sum'] = $ungrouped['sum']->plus($amount);
            }
        }

        $result = [];
        $running = BigDecimal::zero();
        foreach ([...array_values($groups), $ungrouped] as $group) {
            if ($group['lines'] === []) {
                continue;
            }
            // Sum of the line amounts up to each line in display order: the carry-over ("Übertrag")
            // when a page ends after the line.
            foreach ($group['lines'] as $i => $view) {
                $running = $running->plus($view['amount']);
                unset($view['amount']);
                $view['carryOver'] = $this->scope->money(Amount::fromDecimal($running, $invoice->currency));
                $group['lines'][$i] = $view;
            }
            $result[] = [
                'id' => $group['id'],
                'title' => $group['title'],
                'lines' => $group['lines'],
                'subtotal' => $group['showSubtotal'] ? $this->scope->money(Amount::fromDecimal($group['sum'], $invoice->currency)) : null,
            ];
        }

        return $result;
    }
}
