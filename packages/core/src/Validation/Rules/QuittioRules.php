<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use Brick\Math\BigDecimal;
use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Calculation\DeclaredAmountsState;
use Quittio\Core\Calculation\LineCalculator;
use Quittio\Core\Countries\CountryGeneric;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Profiles\Capabilities\SupportsThirdPartyPayments;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Xml\XmlBuilder;

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
        return [...self::linesAndTotals(), ...self::syntaxAndDeclaredData(), ...self::rounding(), ...self::attachmentsAndTexts(), ...self::documentKind()];
    }

    /**
     * Totals, lines and sub lines.
     *
     * @return list<Rule>
     */
    private static function linesAndTotals(): array
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
            // EN 16931 does not require unique identifiers (the official XRechnung test suite has
            // sub lines with the same one), but references to lines, e.g. partial cancellations,
            // need them; InvoiceBuilder rejects duplicates.
            $r('QUITTIO-LINE-ID-DUPLICATE', 'The line identifier :line (BT-126) is used more than once. Lines cannot be referred to unambiguously, e.g. in partial cancellations.', function (InvoiceData $i, RuleReporter $report): void {
                $seen = [];
                foreach ($i->allLines() as $line) {
                    if (($seen[$line->id] ?? 0) === 1) {
                        $report->warning('BT-126', "line {$line->id}.id", ['line' => $line->id]);
                    }
                    $seen[$line->id] = ($seen[$line->id] ?? 0) + 1;
                }
            }),
            $r('QUITTIO-SUBLINE-VAT', 'Sub line :line uses VAT category and rate :value, its parent line uses :expected. Sub lines must have the VAT category and rate of their parent line, because the VAT breakdown is calculated from the parent lines.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->lines as $line) {
                    self::checkSubLineVat($line, $report);
                }
            }),
        ];
    }

    /**
     * Data that a syntax or profile cannot carry as given, and amounts declared by the sender.
     *
     * @return list<Rule>
     */
    private static function syntaxAndDeclaredData(): array
    {
        $r = CallbackRule::l3(...);

        return [
            $r('QUITTIO-PRECEDING-INVOICES-CII', 'The invoice refers to :value preceding invoices (BG-3). CII (ZUGFeRD/Factur-X, XRechnung CII) transmits only the first reference; UBL transmits all of them.', function (InvoiceData $i, RuleReporter $report): void {
                if (count($i->precedingInvoices) > 1 && in_array(Syntax::Cii, $report->context()->profile->syntaxes(), true)) {
                    $report->warning('BT-25', 'precedingInvoices', ['value' => count($i->precedingInvoices)]);
                }
            }),
            // One breakdown per category and rate (BR-E-01 …) carries one code (BT-121); the calculator
            // joins the reason texts (BT-120), so the legally required notes are kept and a warning
            // suffices; the code is omitted, because it would hold only for part of the group.
            $r('QUITTIO-EXEMPTION-CONFLICT', 'Lines with VAT category :category and the same rate use different exemption reason codes (:value). A VAT breakdown carries only one code (BT-121): it is omitted and the reason texts are joined in BT-120. Issue separate invoices if the recipient needs the codes.', function (InvoiceData $i, RuleReporter $report): void {
                $codes = [];
                foreach ($i->lines as $line) {
                    $key = $line->vat->groupKey();
                    if (! $line->vat->category->requiresExemptionReason() || (! Inspect::filled($line->vat->exemptionReason) && $line->vat->exemptionReasonCode === null)) {
                        continue;
                    }
                    $code = $line->vat->exemptionReasonCode->value ?? '–';
                    if (isset($codes[$key]) && ! in_array($code, $codes[$key], true)) {
                        $report->warning('BT-121', "line {$line->id}.vat.exemptionReasonCode", ['category' => $line->vat->category->value, 'value' => implode(', ', [...$codes[$key], $code])]);
                    }
                    $codes[$key][] = $code;
                    $codes[$key] = array_values(array_unique($codes[$key]));
                }
            }),
            $r('QUITTIO-DECLARED-AMOUNTS', 'The declared line net amounts (BT-131) or VAT category tax amounts (BT-117) of the original document differ from the recalculation by more than tolerated (BT-131: 0.02, PEPPOL-EN16931-R120; BT-117: less than one currency unit, BR-CO-17) and were recalculated. The document may not reverse the original exactly; compare its totals with the original.', function (InvoiceData $i, RuleReporter $report): void {
                if (DeclaredAmountsState::fromMeta($i->meta)->isRecalculated()) {
                    $report->warning('BT-131', 'lines');
                }
            }),
            // BG-DEX-09 exists only in the XRechnung extension for UBL invoices (cac:PrepaidPayment,
            // BR-DEX-09). CII and UBL credit notes cannot carry it, other profiles apply BR-CO-16
            // without the third party amounts.
            $r('QUITTIO-THIRD-PARTY-PAYMENTS', 'Third party payments (BG-DEX-09) can only be transmitted in an XRechnung UBL invoice (XRechnung extension). In CII, in credit notes and in other profiles the amount due (BT-115) would contradict BR-CO-16. Use XRechnung UBL with an invoice type code (cancellations: strategy negative_invoice) or invoice the amounts as lines.', function (InvoiceData $i, RuleReporter $report): void {
                if (($i->xrechnungExtension->thirdPartyPayments ?? []) === []) {
                    return;
                }
                $profile = $report->context()->profile;
                $syntax = $report->context()->option('syntax') ?? ($profile->syntaxes() === [Syntax::Cii] ? Syntax::Cii : null);
                if ($syntax === Syntax::Cii || $i->isCreditNote() || ! $profile instanceof SupportsThirdPartyPayments) {
                    $report->error('BG-DEX-09', 'xrechnungExtension.thirdPartyPayments');
                }
            }),
            // The UBL binding of EN 16931 has no cbc:DueDate in a CreditNote; BT-9 goes into
            // cac:PaymentMeans, which needs a payment means code (BT-81).
            $r('QUITTIO-CREDIT-NOTE-DUE-DATE', 'A UBL credit note transmits the payment due date (BT-9) only with payment instructions (BG-16); the due date :value is left out.', function (InvoiceData $i, RuleReporter $report): void {
                $profile = $report->context()->profile;
                $syntax = $report->context()->option('syntax') ?? ($profile->syntaxes() === [Syntax::Ubl] ? Syntax::Ubl : null);
                if ($syntax === Syntax::Ubl && $i->isCreditNote() && $i->dueDate !== null && $i->paymentInstructions === null) {
                    $report->warning('BT-9', 'dueDate', ['value' => $i->dueDate->toIsoString()]);
                }
            }),
            // Also for a partial cancellation aligned with the earlier ones when its VAT differs by more
            // than 0.01 or has the sign of neither its taxable amount nor zero.
        ];
    }

    /**
     * Rounding of VAT and line amounts.
     *
     * @return list<Rule>
     */
    private static function rounding(): array
    {
        $r = CallbackRule::l3(...);

        return [
            $r('QUITTIO-VAT-ROUNDING', 'The VAT amount of category :category (:rate %) differs from taxable amount × rate by :value. The official rules tolerate this, but the amount is not rounded commercially.', function (InvoiceData $i, RuleReporter $report): void {
                self::vatRounding($i, $report->warning(...), self::vatAligned($i) ? false : null);
            }),
            // A partial cancellation whose VAT is rounded cumulatively with the earlier ones of the
            // original (Corrections\CancellationHistory): intended within 0.01 and with the sign of
            // the taxable amount, so only an information.
            $r('QUITTIO-CANCELLATION-VAT-ALIGNED', 'The VAT amount of category :category (:rate %) differs from taxable amount × rate by :value: it is aligned with the earlier partial cancellations of the original, so that all cancellations together reverse exactly its VAT (cumulative rounding, at most 0.01, within the tolerance of BR-CO-17).', function (InvoiceData $i, RuleReporter $report): void {
                if (self::vatAligned($i)) {
                    self::vatRounding($i, $report->info(...), true);
                }
            }),
            // Declared line net amounts kept from another system (Meta::FLAG_DECLARED_AMOUNTS), like
            // QUITTIO-VAT-ROUNDING for BT-117. Lines with sub lines and gross prices are excluded.
            $r('QUITTIO-LINE-ROUNDING', 'The net amount of line :line differs from quantity × price ± allowances and charges by :value, e.g. rounded by another system and kept from the original document. EN 16931 does not check this (Peppol tolerates 0.02), but the amount is not rounded commercially.', function (InvoiceData $i, RuleReporter $report): void {
                $calculator = new LineCalculator;
                foreach ($i->allLines() as $line) {
                    if ($line->netAmount === null || $line->subLines !== [] || $line->price->isGrossMode()) {
                        continue;
                    }
                    try {
                        $expected = $calculator->calculate($line->with(['netAmount' => null]), $i->currency)->netAmount;
                    } catch (CalculationException) {
                        continue;
                    }
                    if ($expected !== null && ! $expected->isEqualTo($line->netAmount)) {
                        $report->warning('BT-131', "line {$line->id}.netAmount", [
                            'line' => $line->id,
                            'value' => $line->netAmount->getAmount()->minus($expected->getAmount())->toString(),
                        ]);
                    }
                }
            }),
        ];
    }

    /**
     * Attachments and texts that the XML cannot carry.
     *
     * @return list<Rule>
     */
    private static function attachmentsAndTexts(): array
    {
        $r = CallbackRule::l3(...);

        return [
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
            // DOMDocument writes such characters unchanged, so the XML would not be well-formed;
            // XmlBuilder refuses them, this rule names the field before.
            $r('QUITTIO-INVALID-CHARACTERS', 'The text in :value contains control characters or invalid UTF-8, which XML cannot represent.', function (InvoiceData $i, RuleReporter $report): void {
                $data = $i->toArray();
                unset($data['meta']);
                foreach (self::nonXmlTexts($data) as $path) {
                    $report->error(null, $path, ['value' => $path]);
                }
            }),
        ];
    }

    /**
     * Country pack, profile, standard version and kind of document.
     *
     * @return list<Rule>
     */
    private static function documentKind(): array
    {
        $r = CallbackRule::l3(...);

        return [
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
            // A proforma invoice is no invoice for tax purposes (section 14 UStG); as e-invoice
            // XML with VAT amounts it would be booked as an invoice (section 14c UStG risk).
            $r('QUITTIO-PROFORMA', 'Type code 325 (proforma invoice) is not an invoice. Use DocumentBuilder::proforma() for proforma documents; e-invoice formats must not carry a proforma.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->typeCodeValue() === InvoiceTypeCode::ProformaInvoice->value) {
                    $report->error('BT-3', 'typeCode');
                }
            }),
        ];
    }

    /**
     * The VAT breakdown of a partial cancellation aligned with the earlier ones and kept as declared.
     */
    private static function vatAligned(InvoiceData $invoice): bool
    {
        $state = DeclaredAmountsState::fromMeta($invoice->meta);

        return $state->vatAligned && $state->isDeclared();
    }

    /**
     * Reports VAT amounts (BT-117) that differ from round2(taxable amount × rate) within the
     * tolerance of BR-CO-17: all ($aligned null), or of a partial cancellation aligned with the
     * earlier ones only those within 0.01 and with the sign of the taxable amount ($aligned true) or
     * only the others ($aligned false).
     *
     * @param  callable(?string, ?string, array<string, string|int>): void  $report
     */
    private static function vatRounding(InvoiceData $invoice, callable $report, ?bool $aligned = null): void
    {
        foreach ($invoice->vatBreakdown as $index => $breakdown) {
            if ($breakdown->rate === null || $breakdown->category->isZeroTax()) {
                continue;
            }
            $taxable = $breakdown->taxableAmount->getAmount();
            $tax = $breakdown->taxAmount->getAmount();
            $expected = $taxable->multipliedBy($breakdown->rate)->dividedBy(100, 2, \Brick\Math\RoundingMode::HalfUp);
            $difference = $tax->minus($expected);
            if ($difference->isZero() || $difference->abs()->isGreaterThan(1)) {
                continue;
            }
            // Aligned VAT of a partial cancellation: within 0.01 and with the sign of the taxable
            // amount (0.00 on 0.00) it is intended ($aligned true), otherwise not ($aligned false).
            if ($aligned !== null) {
                $wrongSign = $taxable->isZero() ? ! $tax->isZero() : (! $tax->isZero() && $tax->getSign() !== $taxable->getSign());
                if ($aligned === ($wrongSign || $difference->abs()->isGreaterThan('0.01'))) {
                    continue;
                }
            }
            $report('BT-117', "vatBreakdown[{$index}].taxAmount", [
                'category' => $breakdown->category->value,
                'rate' => $breakdown->rate->toString(),
                'value' => $difference->toString(),
            ]);
        }
    }

    private static function checkSubLineVat(InvoiceLine $parent, RuleReporter $report): void
    {
        foreach ($parent->subLines as $sub) {
            if ($sub->vat->groupKey() !== $parent->vat->groupKey()) {
                $report->error('BT-151', "line {$sub->id}.vat", [
                    'line' => $sub->id,
                    'value' => self::vatLabel($sub),
                    'expected' => self::vatLabel($parent),
                ]);
            }
            self::checkSubLineVat($sub, $report);
        }
    }

    /**
     * Paths of the strings that XML 1.0 cannot represent.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private static function nonXmlTexts(array $data, string $prefix = ''): array
    {
        $paths = [];
        foreach ($data as $key => $value) {
            $path = is_int($key) ? "{$prefix}[{$key}]" : ($prefix === '' ? $key : "{$prefix}.{$key}");
            if (is_array($value)) {
                $paths = [...$paths, ...self::nonXmlTexts($value, $path)];
            } elseif (is_string($value) && ! XmlBuilder::isXmlText($value)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private static function vatLabel(InvoiceLine $line): string
    {
        return $line->vat->category->value.($line->vat->rate === null ? '' : ' '.$line->vat->rate->toString().' %');
    }
}
