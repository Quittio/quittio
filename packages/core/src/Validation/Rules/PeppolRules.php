<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use Brick\Math\RoundingMode;
use Quittio\Core\Calculation\LineCalculator;
use Quittio\Core\Codes\CodeList;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Profiles\PeppolBis3;
use Quittio\Core\Validation\Rule;

/**
 * Subset of the Peppol BIS Billing 3.0 rules (PEPPOL-EN16931-R…) with readable messages.
 * The complete rule set is checked with the official artifacts (level L5).
 */
final class PeppolRules
{
    /**
     * @return list<Rule>
     */
    public static function all(): array
    {
        $r = CallbackRule::l3(...);

        return [
            $r('PEPPOL-EN16931-R001', 'The business process (BT-23) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->businessProcess ?? PeppolBis3::BUSINESS_PROCESS)) {
                    $report->error('BT-23', 'businessProcess');
                }
            }),
            $r('PEPPOL-EN16931-R002', 'No more than one note is allowed on document level, unless both buyer and seller are German organisations.', function (InvoiceData $i, RuleReporter $report): void {
                $german = $i->seller->address->countryCode === 'DE' && $i->buyer->address->countryCode === 'DE';
                if (count($i->notes) > 1 && ! $german) {
                    $report->error('BG-1', 'notes');
                }
            }),
            $r('PEPPOL-EN16931-R003', 'A buyer reference (BT-10) or a purchase order reference (BT-13) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->buyerReference) && ! Inspect::filled($i->purchaseOrderReference)) {
                    $report->error('BT-10', 'buyerReference');
                }
            }),
            $r('PEPPOL-EN16931-R005', 'The VAT accounting currency code (BT-6) must be different from the invoice currency code (BT-5) when provided. BT-6 is therefore not written.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->taxCurrency !== null && $i->taxCurrency === $i->currency) {
                    $report->warning('BT-6', 'taxCurrency');
                }
            }),
            $r('PEPPOL-EN16931-R010', 'The buyer electronic address (BT-49) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->buyer->electronicAddress === null) {
                    $report->error('BT-49', 'buyer.electronicAddress');
                }
            }),
            $r('PEPPOL-EN16931-R020', 'The seller electronic address (BT-34) must be provided.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->seller->electronicAddress === null) {
                    $report->error('BT-34', 'seller.electronicAddress');
                }
            }),
            // Narrower than BR-CL-25: Peppol delivers to the electronic address, so EM (e-mail) and the
            // other letter schemes of the EAS list are not allowed.
            $r('PEPPOL-EN16931-CL008', 'The electronic address scheme ":value" is not allowed in Peppol.', function (InvoiceData $i, RuleReporter $report): void {
                $addresses = [
                    'seller.electronicAddress.scheme' => ['BT-34-1', $i->seller->electronicAddress],
                    'buyer.electronicAddress.scheme' => ['BT-49-1', $i->buyer->electronicAddress],
                ];
                foreach ($addresses as $path => [$bt, $address]) {
                    $scheme = $address === null ? '' : $address->scheme->value;
                    if ($scheme !== '' && ! CodeList::contains(CodeList::PEPPOL_EAS, $scheme)) {
                        $report->error($bt, $path, ['value' => $scheme]);
                    }
                }
            }),
            $r('PEPPOL-EN16931-R040', 'An allowance or charge amount must equal base amount × percentage / 100 if base amount and percentage are given.', function (InvoiceData $i, RuleReporter $report): void {
                $entries = [];
                foreach ($i->allowances as $index => $a) {
                    $entries["allowances[{$index}]"] = $a;
                }
                foreach ($i->charges as $index => $c) {
                    $entries["charges[{$index}]"] = $c;
                }
                foreach ($entries as $path => $entry) {
                    if ($entry->amount === null || $entry->baseAmount === null || $entry->percent === null) {
                        continue;
                    }
                    $expected = $entry->baseAmount->getAmount()->multipliedBy($entry->percent)->dividedBy(100, 2, RoundingMode::HalfUp);
                    if (! $entry->amount->getAmount()->isEqualTo($expected)) {
                        $report->error($entry->isCharge() ? 'BT-99' : 'BT-92', $path.'.amount');
                    }
                }
            }),
            $r('PEPPOL-EN16931-R041', 'An allowance or charge base amount must be provided when a percentage is provided.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ([...$i->allowances, ...$i->charges] as $entry) {
                    if ($entry->percent !== null && $entry->baseAmount === null) {
                        $report->error($entry->isCharge() ? 'BT-100' : 'BT-93');
                    }
                }
            }),
            $r('PEPPOL-EN16931-R042', 'An allowance or charge percentage must be provided when a base amount is provided.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ([...$i->allowances, ...$i->charges] as $entry) {
                    if ($entry->baseAmount !== null && $entry->percent === null) {
                        $report->error($entry->isCharge() ? 'BT-101' : 'BT-94');
                    }
                }
            }),
            $r('PEPPOL-EN16931-R061', 'The mandate reference (BT-89) must be provided for direct debit.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->paymentInstructions?->meansCode === PaymentMeansCode::SepaDirectDebit
                    && ! Inspect::filled($i->paymentInstructions->directDebit?->mandateReference)) {
                    $report->error('BT-89', 'paymentInstructions.directDebit.mandateReference');
                }
            }),
            // As the official rule: u:slack(BT-131, quantity × (price / base quantity) + charges − allowances, 0.02),
            // unrounded, so that declared amounts of other systems (e.g. in cancellations) pass.
            $r('PEPPOL-EN16931-R120', 'The invoice line net amount of line :line must equal quantity × (net price / base quantity) + charges − allowances (tolerance 0.02).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allLines() as $line) {
                    $expected = LineCalculator::exactAmount($line);
                    if ($line->netAmount === null || $expected === null) {
                        continue;
                    }
                    if ($line->netAmount->getAmount()->minus($expected)->abs()->isGreaterThan('0.02')) {
                        $report->error('BT-131', "line {$line->id}.netAmount", ['line' => $line->id]);
                    }
                }
            }),
            $r('PEPPOL-EN16931-R121', 'The base quantity (BT-149) of line :line must be a positive number above zero.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allLines() as $line) {
                    if ($line->price->baseQuantity !== null && ! $line->price->baseQuantity->isPositive()) {
                        $report->error('BT-149', "line {$line->id}.price.baseQuantity", ['line' => $line->id]);
                    }
                }
            }),
            $r('PEPPOL-EN16931-R130', 'The unit code of the price base quantity (BT-150) of line :line must be the same as the invoiced quantity unit (BT-130).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allLines() as $line) {
                    $base = $line->price->baseQuantityUnit;
                    if ($base !== null && $base->value !== $line->unit->value) {
                        $report->error('BT-150', "line {$line->id}.price.baseQuantityUnit", ['line' => $line->id]);
                    }
                }
            }),
        ];
    }
}
