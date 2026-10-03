<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use Brick\Math\BigDecimal;
use Quittio\Core\Countries\CountryGeneric;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Validation\Rule;

/**
 * Package rules (QUITTIO-…) that go beyond EN 16931.
 */
final class QuittioRules
{
    public const int DEFAULT_ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;

    public const int DEFAULT_ATTACHMENTS_MAX_TOTAL_BYTES = 15 * 1024 * 1024;

    /**
     * @return list<Rule>
     */
    public static function all(): array
    {
        $r = CallbackRule::l3(...);

        return [
            $r('QUITTIO-NEGATIVE-TOTAL', 'The amount due is negative. Consider issuing a credit note (type code 381) with positive amounts instead.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->totals !== null && $i->totals->amountDue->isNegative() && $i->typeCode === InvoiceTypeCode::CommercialInvoice) {
                    $report->warning('BT-115', 'totals.amountDue');
                }
            }),
            $r('QUITTIO-SUBLINES', 'The net amount of line :line must equal the sum of its sub lines (:expected).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allLines() as $line) {
                    if ($line->subLines === [] || $line->netAmount === null) {
                        continue;
                    }
                    $sum = array_reduce(
                        $line->subLines,
                        static fn (BigDecimal $carry, InvoiceLine $sub): BigDecimal => $carry->plus($sub->netAmount?->getAmount() ?? BigDecimal::zero()),
                        BigDecimal::zero(),
                    );
                    if (! $line->netAmount->getAmount()->isEqualTo($sum)) {
                        $report->error('BT-131', "line {$line->id}.netAmount", ['line' => $line->id, 'expected' => $sum->toScale(2)->toString()]);
                    }
                }
            }),
            $r('QUITTIO-EXEMPTION-CONFLICT', 'Lines with VAT category :category and the same rate use different exemption reasons. Only one reason per VAT breakdown can be transmitted.', function (InvoiceData $i, RuleReporter $report): void {
                $reasons = [];
                foreach ($i->lines as $line) {
                    $key = $line->vat->groupKey();
                    $reason = ($line->vat->exemptionReason ?? '').'|'.($line->vat->exemptionReasonCode->value ?? '');
                    if ($reason === '|') {
                        continue;
                    }
                    if (isset($reasons[$key]) && $reasons[$key] !== $reason) {
                        $report->warning('BT-120', "line {$line->id}.vat.exemptionReason", ['category' => $line->vat->category->value]);
                    }
                    $reasons[$key] ??= $reason;
                }
            }),
            $r('QUITTIO-VAT-ROUNDING', 'The VAT amount of category :category (:rate %) differs from taxable amount × rate by :value. The official rules tolerate this, but the amount is not rounded commercially.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->vatBreakdown as $index => $breakdown) {
                    if ($breakdown->rate === null || $breakdown->category->isZeroTax()) {
                        continue;
                    }
                    $expected = $breakdown->taxableAmount->getAmount()->multipliedBy($breakdown->rate)->dividedBy(100, 2, \Brick\Math\RoundingMode::HalfUp);
                    $difference = $breakdown->taxAmount->getAmount()->minus($expected);
                    if (! $difference->isZero() && $difference->abs()->isLessThanOrEqualTo(1)) {
                        $report->warning('BT-117', "vatBreakdown[{$index}].taxAmount", [
                            'category' => $breakdown->category->value,
                            'rate' => $breakdown->rate->toString(),
                            'value' => $difference->toString(),
                        ]);
                    }
                }
            }),
            $r('QUITTIO-ATTACHMENT-FILENAME', 'The file name ":value" of an attached document (BT-125-2) is used more than once.', function (InvoiceData $i, RuleReporter $report): void {
                $seen = [];
                foreach ($i->attachments as $index => $attachment) {
                    if ($attachment->filename === null) {
                        continue;
                    }
                    if (isset($seen[$attachment->filename])) {
                        $report->error('BT-125-2', "attachments[{$index}].filename", ['value' => $attachment->filename]);
                    }
                    $seen[$attachment->filename] = true;
                }
            }),
            $r('QUITTIO-ATTACHMENT-SIZE', 'Attached documents exceed the size limit (:value bytes). Receiving portals reject large invoices.', function (InvoiceData $i, RuleReporter $report): void {
                $context = $report->context();
                $single = $context->intOption('attachment_max_bytes', self::DEFAULT_ATTACHMENT_MAX_BYTES);
                $total = $context->intOption('attachments_max_total_bytes', self::DEFAULT_ATTACHMENTS_MAX_TOTAL_BYTES);
                $sum = 0;
                foreach ($i->attachments as $index => $attachment) {
                    $sum += $attachment->size();
                    if ($attachment->size() > $single) {
                        $report->error('BT-125', "attachments[{$index}].content", ['value' => $single]);
                    }
                }
                if ($sum > $total) {
                    $report->error('BG-24', 'attachments', ['value' => $total]);
                }
            }),
            $r('QUITTIO-GENERIC-COUNTRY', 'No country pack is installed for the seller country :value. Only EN 16931 rules are checked; national mandatory information is not validated.', function (InvoiceData $i, RuleReporter $report): void {
                $leading = $report->context()->countryPacks[0] ?? null;
                if ($leading instanceof CountryGeneric) {
                    $report->warning('BT-40', 'seller.address.countryCode', ['value' => $i->seller->address->countryCode]);
                }
            }),
            $r('QUITTIO-PROFILE-NOT-EINVOICE', 'The profile :value does not produce an e-invoice according to EN 16931 and is accepted only as an explicit choice.', function (InvoiceData $i, RuleReporter $report): void {
                $profile = $report->context()->profile;
                if (! $profile->isEn16931Compliant()) {
                    $report->warning(null, null, ['value' => $profile->name()]);
                }
            }),
            $r('QUITTIO-STANDARD-VERSION', 'The profile :value does not support the EN 16931 version of the invoice.', function (InvoiceData $i, RuleReporter $report): void {
                $profile = $report->context()->profile;
                if (! $profile->supportsStandard($i->standardVersion)) {
                    $report->error(null, 'standardVersion', ['value' => $profile->name()]);
                }
            }),
        ];
    }
}
