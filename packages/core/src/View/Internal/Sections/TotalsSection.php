<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal\Sections;

use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\Internal\RunScope;

/**
 * Document totals (BG-22), the amount due and its label.
 *
 * @internal
 */
final readonly class TotalsSection
{
    public function __construct(private RunScope $scope) {}

    /**
     * @return array<string, mixed> totals
     */
    public function build(): array
    {
        return ['totals' => $this->scope->invoice->totals === null ? null : $this->totals()];
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(): array
    {
        $scope = $this->scope;
        $labels = $scope->baseLabels;
        $t = $scope->invoice->totals ?? throw new CalculationException('Missing totals.');
        $meta = $scope->invoice->meta;
        $taxCurrencyNote = null;
        if ($t->vatTotalInTaxCurrency !== null && $meta->exchangeRate !== null) {
            $taxCurrencyNote = str_replace(
                [':currency', ':amount', ':rate', ':date'],
                [
                    $t->vatTotalInTaxCurrency->getCurrency()->getCurrencyCode(),
                    (string) $scope->money($t->vatTotalInTaxCurrency),
                    $scope->context->formatter->quantity($meta->exchangeRate, $scope->locale),
                    (string) ($scope->date($meta->exchangeRateDate) ?? '–'),
                ],
                $labels['tax_currency_note'],
            );
        }

        $thirdParty = [];
        foreach ($scope->invoice->xrechnungExtension->thirdPartyPayments ?? [] as $payment) {
            $thirdParty[] = ['description' => $payment->description, 'amount' => $scope->money($payment->amount)];
        }

        return [
            'lineNetSum' => $scope->money($t->lineNetSum),
            'allowanceSum' => $scope->money($t->allowanceSum),
            'chargeSum' => $scope->money($t->chargeSum),
            'netTotal' => $scope->money($t->netTotal),
            'vatTotal' => $scope->money($t->vatTotal),
            'vatTotalInTaxCurrency' => $scope->money($t->vatTotalInTaxCurrency),
            'taxCurrencyNote' => $taxCurrencyNote,
            'grossTotal' => $scope->money($t->grossTotal),
            // Nothing prepaid, rounded or paid by third parties: the amount due is the gross total,
            // templates may print it once, as gross total.
            'dueEqualsGross' => ! $scope->isRefund() && ! $scope->selfBilled && $t->amountDue->isEqualTo($t->grossTotal),
            'prepaid' => $scope->money($t->prepaidAmount),
            'rounding' => $scope->money($t->roundingAmount),
            'thirdPartyPayments' => $thirdParty,
            // A refund is shown as a positive amount next to "Erstattungsbetrag" (negative invoices,
            // e.g. cancellations); the raw value keeps the sign of BT-115 as in the XML.
            'amountDue' => $scope->isRefund() && $t->amountDue->isNegative()
                ? new FormattedValue($scope->context->formatter->money($t->amountDue->abs(), $scope->locale), $t->amountDue)
                : $scope->money($t->amountDue),
            // Credit notes and negative invoices are no request to pay: the amount is refunded or
            // offset. On a self-billed invoice the issuer (buyer) pays the amount out.
            'amountDueLabel' => match (true) {
                $scope->isRefund() => $scope->labels['refund_amount'] ?? $labels['refund_amount'],
                $scope->selfBilled => $scope->labels['payout_amount'] ?? $labels['payout_amount'],
                default => $scope->labels['amount_due'] ?? $labels['amount_due'],
            },
        ];
    }
}
