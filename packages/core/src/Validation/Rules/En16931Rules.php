<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Validation\Rule;

/**
 * EN 16931 business rules (BR-…) and conditions (BR-CO-…) checked on the data model.
 *
 * These rules cover the most frequent Schematron findings with readable messages.
 * They do not replace the official Schematron validation (level L5).
 */
final class En16931Rules
{
    /**
     * @return list<Rule>
     */
    public static function all(): array
    {
        return [...self::mandatory(), ...self::conditions(), ...self::sums()];
    }

    /**
     * @return list<Rule>
     */
    private static function mandatory(): array
    {
        $r = CallbackRule::l3(...);

        return [
            $r('BR-02', 'An invoice shall have an invoice number (BT-1).', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->number)) {
                    $report->error('BT-1', 'number');
                }
            }),
            $r('BR-04', 'An invoice shall have an invoice type code (BT-3).', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->typeCodeValue())) {
                    $report->error('BT-3', 'typeCode');
                }
            }),
            $r('BR-05', 'An invoice shall have an invoice currency code (BT-5).', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->currency)) {
                    $report->error('BT-5', 'currency');
                }
            }),
            $r('BR-06', 'An invoice shall contain the seller name (BT-27).', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->seller->name)) {
                    $report->error('BT-27', 'seller.name');
                }
            }),
            $r('BR-07', 'An invoice shall contain the buyer name (BT-44).', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->buyer->name)) {
                    $report->error('BT-44', 'buyer.name');
                }
            }),
            $r('BR-09', 'The seller postal address (BG-5) shall contain a country code (BT-40).', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->seller->address->countryCode)) {
                    $report->error('BT-40', 'seller.address.countryCode');
                }
            }),
            $r('BR-11', 'The buyer postal address (BG-8) shall contain a country code (BT-55).', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->buyer->address->countryCode)) {
                    $report->error('BT-55', 'buyer.address.countryCode');
                }
            }),
            $r('BR-16', 'An invoice shall have at least one invoice line (BG-25).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->lines === []) {
                    $report->error('BG-25', 'lines');
                }
            }),
            $r('BR-17', 'The payee name (BT-59) shall be provided if the payee (BG-10) is different from the seller.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->payee !== null && ! Inspect::filled($i->payee->name)) {
                    $report->error('BT-59', 'payee.name');
                }
            }),
            $r('BR-18', 'The seller tax representative name (BT-62) shall be provided if the seller has a tax representative.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->sellerTaxRepresentative !== null && ! Inspect::filled($i->sellerTaxRepresentative->name)) {
                    $report->error('BT-62', 'sellerTaxRepresentative.name');
                }
            }),
            $r('BR-20', 'The seller tax representative postal address (BG-12) shall contain a country code (BT-69).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->sellerTaxRepresentative !== null && ! Inspect::filled($i->sellerTaxRepresentative->address->countryCode)) {
                    $report->error('BT-69', 'sellerTaxRepresentative.address.countryCode');
                }
            }),
            $r('BR-21', 'Each invoice line (BG-25) shall have an invoice line identifier (BT-126).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allLines() as $index => $line) {
                    if (! Inspect::filled($line->id)) {
                        $report->error('BT-126', "lines[{$index}].id");
                    }
                }
            }),
            $r('BR-25', 'Each invoice line (BG-25) shall contain the item name (BT-153). Line :line.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allLines() as $line) {
                    if (! Inspect::filled($line->item->name)) {
                        $report->error('BT-153', "line {$line->id}.item.name", ['line' => $line->id]);
                    }
                }
            }),
            $r('BR-27', 'The item net price (BT-146) shall not be negative. Line :line.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allLines() as $line) {
                    if ($line->price->netPrice !== null && $line->price->netPrice->isNegative()) {
                        $report->error('BT-146', "line {$line->id}.price.netPrice", ['line' => $line->id]);
                    }
                }
            }),
            $r('BR-28', 'The item gross price (BT-148) shall not be negative. Line :line.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allLines() as $line) {
                    if ($line->price->grossPrice !== null && $line->price->grossPrice->isNegative()) {
                        $report->error('BT-148', "line {$line->id}.price.grossPrice", ['line' => $line->id]);
                    }
                }
            }),
            $r('BR-29', 'The invoicing period end date (BT-74) shall be later or equal to the start date (BT-73).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->invoicingPeriod !== null && ! $i->invoicingPeriod->isValid()) {
                    $report->error('BT-74', 'invoicingPeriod.end');
                }
            }),
            $r('BR-30', 'The invoice line period end date (BT-135) shall be later or equal to the start date (BT-134). Line :line.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allLines() as $line) {
                    if ($line->period instanceof Period && ! $line->period->isValid()) {
                        $report->error('BT-135', "line {$line->id}.period.end", ['line' => $line->id]);
                    }
                }
            }),
            $r('BR-33', 'Each document level allowance (BG-20) shall have a reason (BT-97) or a reason code (BT-98).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allowances as $index => $allowance) {
                    if (! Inspect::filled($allowance->reason) && $allowance->reasonCode === null) {
                        $report->error('BT-97', "allowances[{$index}].reason");
                    }
                }
            }),
            $r('BR-38', 'Each document level charge (BG-21) shall have a reason (BT-104) or a reason code (BT-105).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->charges as $index => $charge) {
                    if (! Inspect::filled($charge->reason) && $charge->reasonCode === null) {
                        $report->error('BT-104', "charges[{$index}].reason");
                    }
                }
            }),
            $r('BR-42', 'Each invoice line allowance (BG-27) shall have a reason (BT-139) or a reason code (BT-140). Line :line.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allLines() as $line) {
                    foreach ($line->allowances as $allowance) {
                        if (! Inspect::filled($allowance->reason) && $allowance->reasonCode === null) {
                            $report->error('BT-139', "line {$line->id}.allowances", ['line' => $line->id]);
                        }
                    }
                }
            }),
            $r('BR-44', 'Each invoice line charge (BG-28) shall have a reason (BT-144) or a reason code (BT-145). Line :line.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allLines() as $line) {
                    foreach ($line->charges as $charge) {
                        if (! Inspect::filled($charge->reason) && $charge->reasonCode === null) {
                            $report->error('BT-144', "line {$line->id}.charges", ['line' => $line->id]);
                        }
                    }
                }
            }),
            $r('BR-48', 'Each VAT breakdown (BG-23) shall have a VAT category rate (BT-119), except if the invoice is not subject to VAT.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->vatBreakdown as $index => $breakdown) {
                    if ($breakdown->category->requiresRate() && $breakdown->rate === null) {
                        $report->error('BT-119', "vatBreakdown[{$index}].rate");
                    }
                }
            }),
            $r('BR-49', 'The payment instructions (BG-16) shall specify the payment means type code (BT-81).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->paymentInstructions !== null && ! Inspect::filled((string) $i->paymentInstructions->meansCode->value)) {
                    $report->error('BT-81', 'paymentInstructions.meansCode');
                }
            }),
            $r('BR-50', 'A payment account identifier (BT-84) shall be present if credit transfer (BG-17) information is provided.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->paymentInstructions->creditTransfers ?? [] as $index => $transfer) {
                    if (! Inspect::filled($transfer->accountId)) {
                        $report->error('BT-84', "paymentInstructions.creditTransfers[{$index}].accountId");
                    }
                }
            }),
            $r('BR-52', 'Each additional supporting document (BG-24) shall contain a supporting document reference (BT-122).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->attachments as $index => $attachment) {
                    if (! Inspect::filled($attachment->id)) {
                        $report->error('BT-122', "attachments[{$index}].id");
                    }
                }
            }),
            $r('BR-53', 'If the VAT accounting currency code (BT-6) is present, the invoice total VAT amount in accounting currency (BT-111) shall be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->taxCurrency !== null && $i->taxCurrency !== $i->currency && $i->totals?->vatTotalInTaxCurrency === null) {
                    $report->error('BT-111', 'meta.exchangeRate');
                }
            }),
            $r('BR-54', 'Each item attribute (BG-32) shall contain an item attribute name (BT-160) and an item attribute value (BT-161). Line :line.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allLines() as $line) {
                    foreach ($line->item->attributes as $attribute) {
                        if (! Inspect::filled($attribute->name) || ! Inspect::filled($attribute->value)) {
                            $report->error('BG-32', "line {$line->id}.item.attributes", ['line' => $line->id]);
                        }
                    }
                }
            }),
            $r('BR-55', 'Each preceding invoice reference (BG-3) shall contain a preceding invoice reference (BT-25).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->precedingInvoices as $index => $reference) {
                    if (! Inspect::filled($reference->number)) {
                        $report->error('BT-25', "precedingInvoices[{$index}].number");
                    }
                }
            }),
            $r('BR-56', 'Each seller tax representative party (BG-11) shall have a seller tax representative VAT identifier (BT-63).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->sellerTaxRepresentative !== null && ! Inspect::filled($i->sellerTaxRepresentative->vatId)) {
                    $report->error('BT-63', 'sellerTaxRepresentative.vatId');
                }
            }),
            $r('BR-57', 'Each deliver to address (BG-15) shall contain a deliver to country code (BT-80).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->delivery?->address instanceof Address && ! Inspect::filled($i->delivery->address->countryCode)) {
                    $report->error('BT-80', 'delivery.address.countryCode');
                }
            }),
            $r('BR-61', 'If the payment means type code (BT-81) means SEPA credit transfer, local credit transfer or non-SEPA international credit transfer, the payment account identifier (BT-84) shall be present.', function (InvoiceData $i, RuleReporter $report): void {
                $code = $i->paymentInstructions === null ? null : (string) $i->paymentInstructions->meansCode->value;
                if (in_array($code, [PaymentMeansCode::CreditTransfer->value, PaymentMeansCode::SepaCreditTransfer->value], true)
                    && $i->paymentInstructions !== null && $i->paymentInstructions->creditTransfers === []) {
                    $report->error('BT-84', 'paymentInstructions.creditTransfers');
                }
            }),
        ];
    }

    /**
     * @return list<Rule>
     */
    private static function conditions(): array
    {
        $r = CallbackRule::l3(...);

        return [
            $r('BR-CO-03', 'The value added tax point date (BT-7) and the value added tax point date code (BT-8) are mutually exclusive.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->taxPointDate !== null && $i->taxPointDateCode !== null) {
                    $report->error('BT-7', 'taxPointDate');
                }
            }),
            $r('BR-CO-09', 'The VAT identifier :value shall have a prefix in accordance with ISO code 3166-1 alpha-2 (Greece may use EL).', function (InvoiceData $i, RuleReporter $report): void {
                $ids = [
                    'seller.vatId' => ['BT-31', $i->seller->vatId],
                    'sellerTaxRepresentative.vatId' => ['BT-63', $i->sellerTaxRepresentative?->vatId],
                    'buyer.vatId' => ['BT-48', $i->buyer->vatId],
                ];
                foreach ($ids as $path => [$bt, $vatId]) {
                    if ($vatId !== null && preg_match('/^[A-Z]{2}/', $vatId) !== 1) {
                        $report->error($bt, $path, ['value' => $vatId]);
                    }
                }
            }),
            $r('BR-CO-18', 'An invoice shall have at least one VAT breakdown group (BG-23).', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->isCalculated() && $i->vatBreakdown === []) {
                    $report->error('BG-23', 'vatBreakdown');
                }
            }),
            $r('BR-CO-25', 'In case the amount due for payment (BT-115) is positive, either the payment due date (BT-9) or the payment terms (BT-20) shall be present.', function (InvoiceData $i, RuleReporter $report): void {
                $due = $i->totals?->amountDue;
                if ($due !== null && $due->isPositive() && $i->dueDate === null
                    && ($i->paymentTerms === null || $i->paymentTerms->isEmpty())) {
                    $report->error('BT-9', 'dueDate');
                }
            }),
            $r('BR-CO-26', 'In order for the buyer to automatically identify a supplier, the seller identifier (BT-29), the seller legal registration identifier (BT-30) and/or the seller VAT identifier (BT-31) shall be present.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->seller->identifiers === [] && $i->seller->legalRegistration === null && ! Inspect::filled($i->seller->vatId)) {
                    $report->error('BT-29', 'seller.identifiers');
                }
            }),
        ];
    }

    /**
     * Sum rules BR-CO-10 to BR-CO-17. They always hold for invoices calculated by the
     * package, but are checked for data that was read from files or set manually.
     *
     * @return list<Rule>
     */
    private static function sums(): array
    {
        $r = CallbackRule::l3(...);
        $sum = static function (iterable $amounts): BigDecimal {
            $total = BigDecimal::zero();
            foreach ($amounts as $amount) {
                if ($amount instanceof Money) {
                    $total = $total->plus($amount->getAmount());
                }
            }

            return $total;
        };
        $value = static fn (?Money $money): BigDecimal => $money?->getAmount() ?? BigDecimal::zero();

        return [
            $r('BR-CO-10', 'Sum of invoice line net amount (BT-106) = Σ invoice line net amount (BT-131).', function (InvoiceData $i, RuleReporter $report) use ($sum): void {
                if ($i->totals !== null && ! $i->totals->lineNetSum->getAmount()->isEqualTo($sum(array_map(static fn (InvoiceLine $l): ?Money => $l->netAmount, $i->lines)))) {
                    $report->error('BT-106', 'totals.lineNetSum');
                }
            }),
            $r('BR-CO-11', 'Sum of allowances on document level (BT-107) = Σ document level allowance amount (BT-92).', function (InvoiceData $i, RuleReporter $report) use ($sum, $value): void {
                if ($i->totals !== null && ! $value($i->totals->allowanceSum)->isEqualTo($sum(array_map(static fn ($a): ?Money => $a->amount, $i->allowances)))) {
                    $report->error('BT-107', 'totals.allowanceSum');
                }
            }),
            $r('BR-CO-12', 'Sum of charges on document level (BT-108) = Σ document level charge amount (BT-99).', function (InvoiceData $i, RuleReporter $report) use ($sum, $value): void {
                if ($i->totals !== null && ! $value($i->totals->chargeSum)->isEqualTo($sum(array_map(static fn ($c): ?Money => $c->amount, $i->charges)))) {
                    $report->error('BT-108', 'totals.chargeSum');
                }
            }),
            $r('BR-CO-13', 'Invoice total amount without VAT (BT-109) = Σ invoice line net amount (BT-131) − sum of allowances (BT-107) + sum of charges (BT-108).', function (InvoiceData $i, RuleReporter $report) use ($value): void {
                $t = $i->totals;
                if ($t !== null && ! $t->netTotal->getAmount()->isEqualTo($t->lineNetSum->getAmount()->minus($value($t->allowanceSum))->plus($value($t->chargeSum)))) {
                    $report->error('BT-109', 'totals.netTotal');
                }
            }),
            $r('BR-CO-14', 'Invoice total VAT amount (BT-110) = Σ VAT category tax amount (BT-117).', function (InvoiceData $i, RuleReporter $report) use ($sum, $value): void {
                if ($i->totals !== null && ! $value($i->totals->vatTotal)->isEqualTo($sum(array_map(static fn ($b): Money => $b->taxAmount, $i->vatBreakdown)))) {
                    $report->error('BT-110', 'totals.vatTotal');
                }
            }),
            $r('BR-CO-15', 'Invoice total amount with VAT (BT-112) = invoice total amount without VAT (BT-109) + invoice total VAT amount (BT-110).', function (InvoiceData $i, RuleReporter $report) use ($value): void {
                $t = $i->totals;
                if ($t !== null && ! $t->grossTotal->getAmount()->isEqualTo($t->netTotal->getAmount()->plus($value($t->vatTotal)))) {
                    $report->error('BT-112', 'totals.grossTotal');
                }
            }),
            $r('BR-CO-16', 'Amount due for payment (BT-115) = invoice total amount with VAT (BT-112) − paid amount (BT-113) + rounding amount (BT-114).', function (InvoiceData $i, RuleReporter $report) use ($value): void {
                $t = $i->totals;
                $thirdParty = BigDecimal::zero();
                foreach ($i->xrechnungExtension->thirdPartyPayments ?? [] as $payment) {
                    $thirdParty = $thirdParty->plus($payment->amount->getAmount());
                }
                if ($t !== null && ! $t->amountDue->getAmount()->isEqualTo($t->grossTotal->getAmount()->minus($value($t->prepaidAmount))->plus($value($t->roundingAmount))->plus($thirdParty))) {
                    $report->error('BT-115', 'totals.amountDue');
                }
            }),
            // The official Schematron accepts a deviation of up to one currency unit; smaller
            // deviations are reported as warning QUITTIO-VAT-ROUNDING.
            $r('BR-CO-17', 'VAT category tax amount (BT-117) = VAT category taxable amount (BT-116) × VAT category rate (BT-119) / 100, rounded to two decimals. Category :category, rate :rate.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->vatBreakdown as $index => $breakdown) {
                    if ($breakdown->rate === null || $breakdown->category->isZeroTax()) {
                        continue;
                    }
                    $expected = $breakdown->taxableAmount->getAmount()->multipliedBy($breakdown->rate)->dividedBy(100, 2, RoundingMode::HalfUp);
                    if ($breakdown->taxAmount->getAmount()->minus($expected)->abs()->isGreaterThan(1)) {
                        $report->error('BT-117', "vatBreakdown[{$index}].taxAmount", [
                            'category' => $breakdown->category->value,
                            'rate' => $breakdown->rate->toString(),
                        ]);
                    }
                }
            }),
        ];
    }
}
