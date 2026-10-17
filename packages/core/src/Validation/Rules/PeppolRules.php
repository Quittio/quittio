<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Quittio\Core\Calculation\LineCalculator;
use Quittio\Core\Codes\CodeList;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\Contact;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\PeppolBis3;
use Quittio\Core\Support\Date;
use Quittio\Core\Validation\Rule;

/**
 * The Peppol BIS Billing 3.0 rules (PEPPOL-EN16931-…, PEPPOL-COMMON-…) that the data of an
 * invoice can violate, with readable messages. Rules that the UBL writer always satisfies and
 * rules covered by an identical EN 16931 rule are not repeated (docs/validation.md lists them).
 * The complete rule set is checked with the official artifacts (level L5).
 */
final class PeppolRules
{
    /**
     * VAT exemption reason codes (BT-121) and the VAT category they require (PEPPOL-EN16931-P0104 … P0111).
     *
     * @var array<string, array{0: string, 1: VatCategory, 2: string}>
     */
    private const array EXEMPTION_CATEGORIES = [
        'PEPPOL-EN16931-P0104' => ['VATEX-EU-G', VatCategory::Export, 'The VAT exemption reason code VATEX-EU-G (BT-121) requires the VAT category G (BT-118), not :category.'],
        'PEPPOL-EN16931-P0105' => ['VATEX-EU-O', VatCategory::NotSubjectToVat, 'The VAT exemption reason code VATEX-EU-O (BT-121) requires the VAT category O (BT-118), not :category.'],
        'PEPPOL-EN16931-P0106' => ['VATEX-EU-IC', VatCategory::IntraCommunitySupply, 'The VAT exemption reason code VATEX-EU-IC (BT-121) requires the VAT category K (BT-118), not :category.'],
        'PEPPOL-EN16931-P0107' => ['VATEX-EU-AE', VatCategory::ReverseCharge, 'The VAT exemption reason code VATEX-EU-AE (BT-121) requires the VAT category AE (BT-118), not :category.'],
        'PEPPOL-EN16931-P0108' => ['VATEX-EU-D', VatCategory::Exempt, 'The VAT exemption reason code VATEX-EU-D (BT-121) requires the VAT category E (BT-118), not :category.'],
        'PEPPOL-EN16931-P0109' => ['VATEX-EU-F', VatCategory::Exempt, 'The VAT exemption reason code VATEX-EU-F (BT-121) requires the VAT category E (BT-118), not :category.'],
        'PEPPOL-EN16931-P0110' => ['VATEX-EU-I', VatCategory::Exempt, 'The VAT exemption reason code VATEX-EU-I (BT-121) requires the VAT category E (BT-118), not :category.'],
        'PEPPOL-EN16931-P0111' => ['VATEX-EU-J', VatCategory::Exempt, 'The VAT exemption reason code VATEX-EU-J (BT-121) requires the VAT category E (BT-118), not :category.'],
    ];

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
            // A missing business process is reported by R001 only.
            $r('PEPPOL-EN16931-R007', 'The business process (BT-23) ":value" must be in the format urn:fdc:peppol.eu:2017:poacc:billing:NN:1.0, where NN is the process number.', function (InvoiceData $i, RuleReporter $report): void {
                $process = $i->businessProcess ?? PeppolBis3::BUSINESS_PROCESS;
                if (Inspect::filled($process) && self::process($i) === null) {
                    $report->error('BT-23', 'businessProcess', ['value' => $process]);
                }
            }),
            $r('PEPPOL-EN16931-R002', 'No more than one note is allowed on document level, unless both buyer and seller are German organisations.', function (InvoiceData $i, RuleReporter $report): void {
                if (count($i->notes) > 1 && ! self::german($i)) {
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
            // The writer leaves out empty values, but writes values of only whitespace, which are
            // empty elements for the Schematron. Mandatory values of only whitespace are reported
            // as missing by the EN 16931 rules (BR-02 …) and are not repeated here.
            $r('PEPPOL-EN16931-R008', 'The value of :field (:bt) contains only whitespace. Peppol does not allow empty elements.', function (InvoiceData $i, RuleReporter $report): void {
                foreach (self::texts($i) as $path => [$bt, $value]) {
                    if ($value !== null && $value !== '' && ! Inspect::filled($value)) {
                        $report->error($bt, $path, ['field' => $path, 'bt' => $bt]);
                    }
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
            // The writer puts the gross price (BT-148) and the discount (BT-147, 0 without one) into
            // the price allowance; the net price (BT-146) is written as it is.
            $r('PEPPOL-EN16931-R046', 'The item net price (BT-146) of line :line must equal the item gross price (BT-148) minus the item price discount (BT-147).', function (InvoiceData $i, RuleReporter $report): void {
                foreach ($i->allLines() as $line) {
                    $price = $line->price;
                    if ($price->grossPrice === null || $price->netPrice === null) {
                        continue;
                    }
                    if (! $price->netPrice->isEqualTo($price->grossPrice->minus($price->discount ?? BigDecimal::zero()))) {
                        $report->error('BT-146', "line {$line->id}.price.netPrice", ['line' => $line->id]);
                    }
                }
            }),
            // BT-111 is written only together with BT-6 (BR-53), e.g. a negative exchange rate turns the sign.
            $r('PEPPOL-EN16931-R055', 'The invoice total VAT amount (BT-110) and the invoice total VAT amount in accounting currency (BT-111) must have the same operational sign.', function (InvoiceData $i, RuleReporter $report): void {
                $vat = $i->totals?->vatTotal?->getAmount()->getSign() ?? 0;
                $converted = $i->totals?->vatTotalInTaxCurrency?->getAmount()->getSign() ?? 0;
                if ($vat * $converted < 0) {
                    $report->error('BT-111', 'totals.vatTotalInTaxCurrency');
                }
            }),
            $r('PEPPOL-EN16931-R061', 'The mandate reference (BT-89) must be provided for direct debit.', function (InvoiceData $i, RuleReporter $report): void {
                if ($i->paymentInstructions?->meansCode === PaymentMeansCode::SepaDirectDebit
                    && ! Inspect::filled($i->paymentInstructions->directDebit?->mandateReference)) {
                    $report->error('BT-89', 'paymentInstructions.directDebit.mandateReference');
                }
            }),
            $r('PEPPOL-EN16931-R110', 'The start date (BT-134) of the period of line :line must not be before the start of the invoicing period (BT-73).', function (InvoiceData $i, RuleReporter $report): void {
                $start = $i->invoicingPeriod?->start;
                if ($start === null) {
                    return;
                }
                foreach ($i->lines as $line) {
                    if ($line->period?->start !== null && $line->period->start->isBefore($start)) {
                        $report->error('BT-134', "line {$line->id}.period.start", ['line' => $line->id]);
                    }
                }
            }),
            $r('PEPPOL-EN16931-R111', 'The end date (BT-135) of the period of line :line must not be after the end of the invoicing period (BT-74).', function (InvoiceData $i, RuleReporter $report): void {
                $end = $i->invoicingPeriod?->end;
                if ($end === null) {
                    return;
                }
                foreach ($i->lines as $line) {
                    if ($line->period?->end !== null && $line->period->end->isAfter($end)) {
                        $report->error('BT-135', "line {$line->id}.period.end", ['line' => $line->id]);
                    }
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
            ...self::codeRules(),
            ...self::identifierRules(),
        ];
    }

    /**
     * Restricted code lists and formats: currencies, document type codes, VAT exemption reason
     * codes and dates.
     *
     * @return list<Rule>
     */
    private static function codeRules(): array
    {
        $r = CallbackRule::l3(...);

        $rules = [
            // Peppol checks the currencies of all amounts against its own ISO 4217 list (BT-5, and
            // BT-6 for BT-111). Codes that are not in the EN 16931 list are reported by BR-CL-04 and BR-CL-05.
            $r('PEPPOL-EN16931-CL007', 'The currency code ":value" is not allowed in Peppol.', function (InvoiceData $i, RuleReporter $report): void {
                $currencies = [
                    'currency' => ['BT-5', $i->currency],
                    'taxCurrency' => ['BT-6', $i->totals?->vatTotalInTaxCurrency?->getCurrency()->getCurrencyCode()],
                ];
                foreach ($currencies as $path => [$bt, $code]) {
                    if ($code !== null && CodeList::contains(CodeList::CURRENCIES, $code) && ! CodeList::contains(CodeList::PEPPOL_CURRENCIES, $code)) {
                        $report->error($bt, $path, ['value' => $code]);
                    }
                }
            }),
            $r('PEPPOL-EN16931-F001', 'The date :value (:bt) must be formatted YYYY-MM-DD.', function (InvoiceData $i, RuleReporter $report): void {
                foreach (self::dates($i) as $path => [$bt, $date]) {
                    if ($date !== null && strlen($date->toIsoString()) !== 10) {
                        $report->error($bt, $path, ['value' => $date->toIsoString(), 'bt' => $bt]);
                    }
                }
            }),
            // Narrower than BR-CL-01, only for business process 01 (the default). Codes that are not in
            // the EN 16931 list are reported by BR-CL-01.
            $r('PEPPOL-EN16931-P0100', 'The invoice type code (BT-3) ":value" is not allowed in Peppol business process 01.', function (InvoiceData $i, RuleReporter $report): void {
                $code = self::normalize($i->typeCodeValue());
                if (! $i->isCreditNote() && self::process($i) === '01'
                    && CodeList::contains(CodeList::INVOICE_TYPES, $code) && ! CodeList::contains(CodeList::PEPPOL_INVOICE_TYPES, $code)) {
                    $report->error('BT-3', 'typeCode', ['value' => $code]);
                }
            }),
            $r('PEPPOL-EN16931-P0101', 'The credit note type code (BT-3) ":value" is not allowed in Peppol business process 01.', function (InvoiceData $i, RuleReporter $report): void {
                $code = self::normalize($i->typeCodeValue());
                if ($i->isCreditNote() && self::process($i) === '01'
                    && CodeList::contains(CodeList::INVOICE_TYPES, $code) && ! CodeList::contains(CodeList::PEPPOL_CREDIT_NOTE_TYPES, $code)) {
                    $report->error('BT-3', 'typeCode', ['value' => $code]);
                }
            }),
            $r('PEPPOL-EN16931-P0112', 'The invoice type codes 326 (partial invoice) and 384 (corrected invoice) are only allowed when both buyer and seller are German organisations.', function (InvoiceData $i, RuleReporter $report): void {
                if (! $i->isCreditNote() && in_array(self::normalize($i->typeCodeValue()), ['326', '384'], true) && ! self::german($i)) {
                    $report->error('BT-3', 'typeCode');
                }
            }),
        ];

        // The writer writes the exemption reason code only in the VAT breakdown (cac:TaxSubtotal).
        foreach (self::EXEMPTION_CATEGORIES as $code => [$exemption, $category, $fallback]) {
            $rules[] = $r($code, $fallback, function (InvoiceData $i, RuleReporter $report) use ($exemption, $category): void {
                foreach ($i->vatBreakdown as $index => $breakdown) {
                    $value = $breakdown->exemptionReasonCode?->value;
                    if ($value !== null && strtoupper($value) === $exemption && $breakdown->category !== $category) {
                        $report->error('BT-121', "vatBreakdown[{$index}].exemptionReasonCode", ['category' => $breakdown->category->value]);
                    }
                }
            });
        }

        return $rules;
    }

    /**
     * PEPPOL-COMMON-R040 …: format and check digits of identifiers with these schemes, wherever an
     * electronic address (cbc:EndpointID), a party identifier (cac:PartyIdentification/cbc:ID) or
     * a legal registration identifier (cbc:CompanyID) uses them.
     *
     * @return list<Rule>
     */
    private static function identifierRules(): array
    {
        /** @param Closure(string): bool $valid */
        $rule = static fn (string $code, string $scheme, string $fallback, Closure $valid, bool $warning = false, bool $endpointsOnly = false): Rule => CallbackRule::l3($code, $fallback, static function (InvoiceData $i, RuleReporter $report) use ($scheme, $valid, $warning, $endpointsOnly): void {
            foreach (self::identifiers($i) as $path => [$bt, $idScheme, $value, $endpoint]) {
                if ($idScheme !== $scheme || ($endpointsOnly && ! $endpoint) || $valid($value)) {
                    continue;
                }
                $warning
                    ? $report->warning($bt, $path, ['value' => $value])
                    : $report->error($bt, $path, ['value' => $value]);
            }
        });
        $digits = static fn (string $value, int $length): bool => preg_match('/^[0-9]{'.$length.'}$/', $value) === 1;

        return [
            $rule('PEPPOL-COMMON-R040', '0088', 'The GLN ":value" (scheme 0088) must have a valid format according to the GS1 rules.',
                static fn (string $v): bool => preg_match('/^[0-9]+$/', self::normalize($v)) === 1 && self::gln(self::normalize($v))),
            $rule('PEPPOL-COMMON-R041', '0192', 'The Norwegian organisation number ":value" (scheme 0192) must consist of nine digits with a valid check digit.',
                static fn (string $v): bool => $digits(self::normalize($v), 9) && self::mod11(self::normalize($v))),
            $rule('PEPPOL-COMMON-R042', '0184', 'The Danish organisation number (CVR) ":value" (scheme 0184) must consist of eight digits, optionally preceded by DK.',
                static fn (string $v): bool => (str_starts_with($v, 'DK') && $digits(substr($v, 2), 8)) || $digits($v, 8)),
            $rule('PEPPOL-COMMON-R043', '0208', 'The Belgian enterprise number ":value" (scheme 0208) must consist of ten digits with valid check digits.',
                static fn (string $v): bool => $digits(self::normalize($v), 10) && self::mod97(self::normalize($v))),
            $rule('PEPPOL-COMMON-R044', '0201', 'The IPA code ":value" (scheme 0201) should consist of six letters or digits.',
                static fn (string $v): bool => preg_match('/^[A-Za-z0-9]{6}$/', self::normalize($v)) === 1, warning: true),
            $rule('PEPPOL-COMMON-R045', '0210', 'The Italian tax code (codice fiscale) ":value" (scheme 0210) should be stated in the correct format.',
                static fn (string $v): bool => self::codiceFiscale(self::normalize($v)), warning: true),
            $rule('PEPPOL-COMMON-R046', '9907', 'The Italian tax code (codice fiscale) ":value" (scheme 9907) should be stated in the correct format.',
                static fn (string $v): bool => self::codiceFiscale(self::normalize($v)), warning: true, endpointsOnly: true),
            $rule('PEPPOL-COMMON-R047', '0211', 'The Italian VAT number (partita IVA) ":value" (scheme 0211) should be stated in the correct format.',
                static fn (string $v): bool => self::partitaIva(self::normalize($v)), warning: true),
            $rule('PEPPOL-COMMON-R049', '0007', 'The Swedish organisation number ":value" (scheme 0007) must consist of ten digits with a valid check digit.',
                static fn (string $v): bool => $digits(self::normalize($v), 10) && self::luhn(self::normalize($v))),
            $rule('PEPPOL-COMMON-R050', '0151', 'The Australian Business Number (ABN) ":value" (scheme 0151) must consist of eleven digits with valid check digits.',
                static fn (string $v): bool => $digits(self::normalize($v), 11) && self::abn(self::normalize($v))),
            $rule('PEPPOL-COMMON-R052', '0096', 'The Danish P number ":value" (scheme 0096) should consist of ten digits.',
                static fn (string $v): bool => $digits($v, 10), warning: true),
            $rule('PEPPOL-COMMON-R053', '0198', 'The Danish ERSTORG number (SE) ":value" (scheme 0198) should consist of DK and eight digits.',
                static fn (string $v): bool => str_starts_with($v, 'DK') && $digits(substr($v, 2), 8), warning: true),
        ];
    }

    /**
     * The process number NN of urn:fdc:peppol.eu:2017:poacc:billing:NN:1.0 ($profile of the
     * Schematron, which matches without anchors), null if the business process has another format.
     */
    private static function process(InvoiceData $invoice): ?string
    {
        $process = self::normalize($invoice->businessProcess ?? PeppolBis3::BUSINESS_PROCESS);

        return preg_match('/urn:fdc:peppol.eu:2017:poacc:billing:([0-9]{2}):1.0/', $process) === 1 ? (explode(':', $process)[6] ?? null) : null;
    }

    /**
     * Both seller and buyer have a German postal address (PEPPOL-EN16931-R002, P0112).
     */
    private static function german(InvoiceData $invoice): bool
    {
        return strtoupper(self::normalize($invoice->seller->address->countryCode)) === 'DE'
            && strtoupper(self::normalize($invoice->buyer->address->countryCode)) === 'DE';
    }

    /**
     * XPath normalize-space().
     */
    private static function normalize(string $value): string
    {
        return (string) preg_replace('/[ \t\r\n]+/', ' ', trim($value, " \t\r\n"));
    }

    /**
     * The optional text values the UBL writer writes as element content.
     *
     * @return array<string, array{0: string, 1: ?string}> path => [business term, value]
     */
    private static function texts(InvoiceData $i): array
    {
        $seller = $i->seller;
        $buyer = $i->buyer;
        $payment = $i->paymentInstructions;
        $texts = [
            'buyerReference' => ['BT-10', $i->buyerReference],
            'projectReference.id' => ['BT-11', $i->projectReference?->id],
            'contractReference' => ['BT-12', $i->contractReference],
            'purchaseOrderReference' => ['BT-13', $i->purchaseOrderReference],
            'salesOrderReference' => ['BT-14', $i->salesOrderReference],
            'receivingAdviceReference' => ['BT-15', $i->receivingAdviceReference],
            'despatchAdviceReference' => ['BT-16', $i->despatchAdviceReference],
            'tenderReference' => ['BT-17', $i->tenderReference],
            'invoicedObject.value' => ['BT-18', $i->invoicedObject?->value],
            'buyerAccountingReference' => ['BT-19', $i->buyerAccountingReference],
            'seller.tradingName' => ['BT-28', $seller->tradingName],
            'seller.legalRegistration.value' => ['BT-30', $seller->legalRegistration?->value],
            'seller.vatId' => ['BT-31', $seller->vatId],
            'seller.taxNumber' => ['BT-32', $seller->taxNumber],
            'seller.additionalLegalInfo' => ['BT-33', $seller->additionalLegalInfo],
            'seller.electronicAddress.value' => ['BT-34', $seller->electronicAddress?->value],
            ...self::addressTexts('seller.address', $seller->address, ['BT-35', 'BT-36', 'BT-162', 'BT-37', 'BT-38', 'BT-39']),
            ...self::contactTexts('seller.contact', $seller->contact, ['BT-41', 'BT-42', 'BT-43']),
            'buyer.tradingName' => ['BT-45', $buyer->tradingName],
            'buyer.identifier.value' => ['BT-46', $buyer->identifier?->value],
            'buyer.legalRegistration.value' => ['BT-47', $buyer->legalRegistration?->value],
            'buyer.vatId' => ['BT-48', $buyer->vatId],
            'buyer.electronicAddress.value' => ['BT-49', $buyer->electronicAddress?->value],
            ...self::addressTexts('buyer.address', $buyer->address, ['BT-50', 'BT-51', 'BT-163', 'BT-52', 'BT-53', 'BT-54']),
            ...self::contactTexts('buyer.contact', $buyer->contact, ['BT-56', 'BT-57', 'BT-58']),
            'payee.identifier.value' => ['BT-60', $i->payee?->identifier?->value],
            'payee.legalRegistration.value' => ['BT-61', $i->payee?->legalRegistration?->value],
            ...self::addressTexts('sellerTaxRepresentative.address', $i->sellerTaxRepresentative?->address, ['BT-64', 'BT-65', 'BT-164', 'BT-66', 'BT-67', 'BT-68']),
            'delivery.recipientName' => ['BT-70', $i->delivery?->recipientName],
            'delivery.locationId.value' => ['BT-71', $i->delivery?->locationId?->value],
            ...self::addressTexts('delivery.address', $i->delivery?->address, ['BT-75', 'BT-76', 'BT-165', 'BT-77', 'BT-78', 'BT-79']),
            'paymentInstructions.remittanceInformation' => ['BT-83', $payment?->remittanceInformation],
            'paymentInstructions.card.primaryAccountNumber' => ['BT-87', $payment?->card?->primaryAccountNumber],
            'paymentInstructions.card.holderName' => ['BT-88', $payment?->card?->holderName],
            'paymentInstructions.directDebit.mandateReference' => ['BT-89', $payment?->directDebit?->mandateReference],
            'paymentInstructions.directDebit.creditorId' => ['BT-90', $payment?->directDebit?->creditorId],
            'paymentInstructions.directDebit.debitedAccountId' => ['BT-91', $payment?->directDebit?->debitedAccountId],
        ];
        foreach ($i->notes as $index => $note) {
            $texts["notes[{$index}].content"] = ['BT-22', $note->content];
        }
        foreach ($seller->identifiers as $index => $identifier) {
            $texts["seller.identifiers[{$index}].value"] = ['BT-29', $identifier->value];
        }
        foreach ($payment === null ? [] : $payment->creditTransfers as $index => $transfer) {
            $texts["paymentInstructions.creditTransfers[{$index}].accountName"] = ['BT-85', $transfer->accountName];
            $texts["paymentInstructions.creditTransfers[{$index}].serviceProviderId"] = ['BT-86', $transfer->serviceProviderId];
        }
        // A reason text is mandatory without a reason code (BR-33 …).
        foreach ($i->allowances as $index => $allowance) {
            $texts["allowances[{$index}].reason"] = ['BT-97', $allowance->reasonCode === null ? null : $allowance->reason];
        }
        foreach ($i->charges as $index => $charge) {
            $texts["charges[{$index}].reason"] = ['BT-104', $charge->reasonCode === null ? null : $charge->reason];
        }
        foreach ($i->vatBreakdown as $index => $breakdown) {
            $texts["vatBreakdown[{$index}].exemptionReason"] = ['BT-120', $breakdown->exemptionReason];
        }
        foreach ($i->attachments as $index => $attachment) {
            $texts["attachments[{$index}].description"] = ['BT-123', $attachment->description];
            $texts["attachments[{$index}].externalUri"] = ['BT-124', $attachment->externalUri];
        }
        foreach ($i->allLines() as $line) {
            $prefix = "line {$line->id}";
            $texts["{$prefix}.note"] = ['BT-127', $line->note];
            $texts["{$prefix}.objectId.value"] = ['BT-128', $line->objectId?->value];
            $texts["{$prefix}.orderLineReference"] = ['BT-132', $line->orderLineReference];
            $texts["{$prefix}.buyerAccountingReference"] = ['BT-133', $line->buyerAccountingReference];
            foreach ($line->allowances as $index => $allowance) {
                $texts["{$prefix}.allowances[{$index}].reason"] = ['BT-139', $allowance->reasonCode === null ? null : $allowance->reason];
            }
            foreach ($line->charges as $index => $charge) {
                $texts["{$prefix}.charges[{$index}].reason"] = ['BT-144', $charge->reasonCode === null ? null : $charge->reason];
            }
            $texts["{$prefix}.item.description"] = ['BT-154', $line->item->description];
            $texts["{$prefix}.item.sellerId"] = ['BT-155', $line->item->sellerId];
            $texts["{$prefix}.item.buyerId"] = ['BT-156', $line->item->buyerId];
            $texts["{$prefix}.item.standardId.value"] = ['BT-157', $line->item->standardId?->value];
            foreach ($line->item->classifications as $index => $classification) {
                $texts["{$prefix}.item.classifications[{$index}].code"] = ['BT-158', $classification->code];
            }
        }

        return $texts;
    }

    /**
     * Address lines, city, post code and subdivision; the country code is mandatory (BR-09 …).
     *
     * @param  array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}  $bts
     * @return array<string, array{0: string, 1: ?string}>
     */
    private static function addressTexts(string $path, ?Address $address, array $bts): array
    {
        if ($address === null) {
            return [];
        }

        return [
            $path.'.line1' => [$bts[0], $address->line1],
            $path.'.line2' => [$bts[1], $address->line2],
            $path.'.line3' => [$bts[2], $address->line3],
            $path.'.city' => [$bts[3], $address->city],
            $path.'.postcode' => [$bts[4], $address->postcode],
            $path.'.subdivision' => [$bts[5], $address->subdivision],
        ];
    }

    /**
     * @param  array{0: string, 1: string, 2: string}  $bts
     * @return array<string, array{0: string, 1: ?string}>
     */
    private static function contactTexts(string $path, ?Contact $contact, array $bts): array
    {
        if ($contact === null) {
            return [];
        }

        return [
            $path.'.name' => [$bts[0], $contact->name],
            $path.'.phone' => [$bts[1], $contact->phone],
            $path.'.email' => [$bts[2], $contact->email],
        ];
    }

    /**
     * The dates the UBL writer writes as cbc:IssueDate, cbc:DueDate, cbc:TaxPointDate,
     * cbc:StartDate, cbc:EndDate and cbc:ActualDeliveryDate (a credit note carries the due date
     * as cbc:PaymentDueDate, which PEPPOL-EN16931-F001 does not check).
     *
     * @return array<string, array{0: string, 1: ?Date}> path => [business term, date]
     */
    private static function dates(InvoiceData $i): array
    {
        $dates = [
            'issueDate' => ['BT-2', $i->issueDate],
            'taxPointDate' => ['BT-7', $i->taxPointDate],
            'dueDate' => ['BT-9', $i->isCreditNote() ? null : $i->dueDate],
            'delivery.actualDeliveryDate' => ['BT-72', $i->delivery?->actualDeliveryDate],
            'invoicingPeriod.start' => ['BT-73', $i->invoicingPeriod?->start],
            'invoicingPeriod.end' => ['BT-74', $i->invoicingPeriod?->end],
        ];
        foreach ($i->precedingInvoices as $index => $preceding) {
            $dates["precedingInvoices[{$index}].issueDate"] = ['BT-26', $preceding->issueDate];
        }
        foreach ($i->allLines() as $line) {
            $dates["line {$line->id}.period.start"] = ['BT-134', $line->period?->start];
            $dates["line {$line->id}.period.end"] = ['BT-135', $line->period?->end];
        }

        return $dates;
    }

    /**
     * Electronic addresses, party identifiers and legal registration identifiers with their scheme.
     *
     * @return array<string, array{0: string, 1: ?string, 2: string, 3: bool}> path => [business term, scheme, value, electronic address]
     */
    private static function identifiers(InvoiceData $i): array
    {
        $seller = $i->seller;
        $buyer = $i->buyer;
        $identifiers = [];
        if ($seller->electronicAddress !== null) {
            $identifiers['seller.electronicAddress.value'] = ['BT-34', $seller->electronicAddress->scheme->value, $seller->electronicAddress->value, true];
        }
        if ($buyer->electronicAddress !== null) {
            $identifiers['buyer.electronicAddress.value'] = ['BT-49', $buyer->electronicAddress->scheme->value, $buyer->electronicAddress->value, true];
        }
        foreach ($seller->identifiers as $index => $identifier) {
            $identifiers["seller.identifiers[{$index}].value"] = ['BT-29', $identifier->schemeValue(), $identifier->value, false];
        }
        $parties = [
            'seller.legalRegistration' => ['BT-30', $seller->legalRegistration],
            'buyer.identifier' => ['BT-46', $buyer->identifier],
            'buyer.legalRegistration' => ['BT-47', $buyer->legalRegistration],
            'payee.identifier' => ['BT-60', $i->payee?->identifier],
            'payee.legalRegistration' => ['BT-61', $i->payee?->legalRegistration],
        ];
        foreach ($parties as $path => [$bt, $identifier]) {
            if ($identifier !== null) {
                $identifiers[$path.'.value'] = [$bt, $identifier->schemeValue(), $identifier->value, false];
            }
        }

        return $identifiers;
    }

    /**
     * u:gln of the Schematron: GS1 check digit (weights 3 and 1 from the right).
     */
    private static function gln(string $value): bool
    {
        $length = strlen($value) - 1;
        $sum = 0;
        for ($position = 0; $position < $length; $position++) {
            $sum += (int) $value[$length - 1 - $position] * ($position % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) $value[$length];
    }

    /**
     * u:mod11 of the Schematron: weights 2 to 7 from the right, check digit 11 − sum mod 11.
     */
    private static function mod11(string $value): bool
    {
        $length = strlen($value) - 1;
        $sum = 0;
        for ($position = 0; $position < $length; $position++) {
            $sum += (int) $value[$length - 1 - $position] * ($position % 6 + 2);
        }

        return (int) $value > 0 && (11 - $sum % 11) % 11 === (int) $value[$length];
    }

    /**
     * u:mod97-0208 of the Schematron: the last two digits are 97 − (first eight digits mod 97).
     */
    private static function mod97(string $value): bool
    {
        return (int) substr($value, 8, 2) === 97 - (int) substr($value, 0, 8) % 97;
    }

    /**
     * u:checkSEOrgnr of the Schematron: Luhn check digit of the first nine digits.
     */
    private static function luhn(string $value): bool
    {
        $sum = 0;
        for ($position = 1; $position <= 9; $position++) {
            $digit = (int) $value[9 - $position];
            $sum += $position % 2 === 1 ? intdiv($digit * 2, 10) + $digit * 2 % 10 : $digit;
        }

        return (10 - $sum % 10) % 10 === (int) $value[9];
    }

    /**
     * u:abn of the Schematron: weighted sum of the eleven digits (first digit minus one) divisible by 89.
     */
    private static function abn(string $value): bool
    {
        $sum = ((int) $value[0] - 1) * 10;
        foreach ([1, 3, 5, 7, 9, 11, 13, 15, 17, 19] as $index => $weight) {
            $sum += (int) $value[$index + 1] * $weight;
        }

        return $sum % 89 === 0;
    }

    /**
     * u:checkCF of the Schematron: eleven digits, or sixteen characters in the pattern of a
     * personal codice fiscale (positions 7–8 and 10–11 castable as integer).
     */
    private static function codiceFiscale(string $value): bool
    {
        $integer = '(?:[0-9]{2}|[+-][0-9]| [0-9]|[0-9] )';

        return match (mb_strlen($value)) {
            11 => preg_match('/^[+-]?[0-9]+$/', $value) === 1,
            16 => preg_match('/^[A-Za-z]{6}'.$integer.'[A-Za-z]'.$integer.'.{3}[0-9][A-Za-z]$/su', $value) === 1,
            default => false,
        };
    }

    /**
     * u:checkPIVAseIT of the Schematron: with the prefix IT eleven digits with a valid check
     * digit; values without the prefix are not checked.
     */
    private static function partitaIva(string $value): bool
    {
        $country = substr($value, 0, 2);
        if ($country !== 'IT' && $country !== 'it') {
            return true;
        }
        $number = substr($value, 2);
        if (preg_match('/^[0-9]{11}$/', $number) !== 1) {
            return false;
        }
        $sum = 0;
        foreach (str_split($number) as $index => $digit) {
            $sum += $index % 2 === 1 ? (int) '0246813579'[(int) $digit] : (int) $digit;
        }

        return $sum % 10 === 0;
    }
}
