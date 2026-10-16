<?php

declare(strict_types=1);

namespace Quittio\Core\Reading;

use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Money\Money;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Quittio\Core\Codes\CodeList;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowance;
use Quittio\Core\Data\AllowanceCharge\DocumentCharge;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Totals\Totals;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Enums\AllowanceReasonCode;
use Quittio\Core\Enums\ChargeReasonCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Throwable;

/**
 * Base class for XML readers: XPath access, tolerant value conversion and findings.
 *
 * @internal
 */
abstract class XPathReader
{
    /** Placeholder for a missing or unreadable issue date (BT-2), reported as BR-03. */
    public const string MISSING_DATE = '1970-01-01';

    /** Longest accepted number literal (digits, sign and decimal point). */
    public const int MAX_NUMBER_LENGTH = 40;

    /**
     * Amounts with at most two decimals (EN 16931 BR-DEC-…).
     *
     * @var array<string, string>
     */
    public const array DECIMAL_RULES = [
        'BT-92' => 'BR-DEC-01', 'BT-93' => 'BR-DEC-02', 'BT-99' => 'BR-DEC-05', 'BT-100' => 'BR-DEC-06',
        'BT-106' => 'BR-DEC-09', 'BT-107' => 'BR-DEC-10', 'BT-108' => 'BR-DEC-11', 'BT-109' => 'BR-DEC-12',
        'BT-110' => 'BR-DEC-13', 'BT-112' => 'BR-DEC-14', 'BT-111' => 'BR-DEC-15', 'BT-113' => 'BR-DEC-16',
        'BT-114' => 'BR-DEC-17', 'BT-115' => 'BR-DEC-18', 'BT-116' => 'BR-DEC-19', 'BT-117' => 'BR-DEC-20',
        'BT-131' => 'BR-DEC-23', 'BT-136' => 'BR-DEC-24', 'BT-137' => 'BR-DEC-25', 'BT-141' => 'BR-DEC-27',
        'BT-142' => 'BR-DEC-28',
    ];

    protected DOMXPath $xpath;

    /** Invoice currency (BT-5) of the document being read. */
    protected string $currency = 'XXX';

    /** @var list<ValidationMessage> */
    protected array $findings = [];

    /**
     * @param  array<string, string>  $namespaces
     */
    protected function init(DOMDocument $document, array $namespaces): void
    {
        $this->xpath = new DOMXPath($document);
        foreach ($namespaces as $prefix => $uri) {
            $this->xpath->registerNamespace($prefix, $uri);
        }
        $this->findings = [];
    }

    protected function value(DOMNode $context, string $path): ?string
    {
        $node = $this->node($context, $path);
        if ($node === null) {
            return null;
        }

        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }

    protected function attribute(DOMNode $context, string $path, string $attribute): ?string
    {
        $node = $this->node($context, $path);
        if (! $node instanceof DOMElement || ! $node->hasAttribute($attribute)) {
            return null;
        }

        $value = trim($node->getAttribute($attribute));

        return $value === '' ? null : $value;
    }

    protected function node(DOMNode $context, string $path): ?DOMElement
    {
        $result = $this->xpath->query($path, $context);
        if ($result === false) {
            return null;
        }
        $node = $result->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /**
     * @return list<DOMElement>
     */
    protected function nodes(DOMNode $context, string $path): array
    {
        $result = $this->xpath->query($path, $context);
        if ($result === false) {
            return [];
        }

        $nodes = [];
        foreach ($result as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Reads a ChargeIndicator (xs:boolean): true for a charge, false for an allowance, null when it is missing or
     * has another value. xs:boolean allows "true", "false", "1" and "0" with surrounding white space.
     */
    protected function chargeIndicator(DOMNode $context, string $path): ?bool
    {
        return match ($this->value($context, $path)) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    /**
     * Reports an allowance or charge whose ChargeIndicator is missing or invalid; the entry is not read.
     *
     * @param  string  $group  BG-20 for the document level, BG-27 for lines
     */
    protected function invalidChargeIndicator(DOMNode $context, string $path, string $group): void
    {
        $this->finding('QUITTIO-READER-CHARGE-INDICATOR', sprintf(
            'An allowance or charge (%s) has the charge indicator "%s" instead of true or false and was not read.',
            $group === 'BG-27' ? 'BG-27/BG-28' : 'BG-20/BG-21',
            self::excerpt($this->value($context, $path) ?? ''),
        ), $group, Severity::Error);
    }

    /**
     * Strict ISO date (UBL xs:date: YYYY-MM-DD with optional time zone).
     */
    /**
     * Document totals (BG-22) from the node $paths['node'] of $context; $paths names the
     * elements of the syntax relative to that node. Missing required totals are reported
     * (BR-12 to BR-15) and read as zero. With $vatWithoutCurrency, a total VAT amount (BT-110)
     * without currencyID is read in the invoice currency and reported.
     *
     * @param  array{node: string, vat: string, lineNet: string, allowance: string, charge: string, net: string, gross: string, due: string, prepaid: string, rounding: string}  $paths
     */
    protected function totals(DOMElement $context, array $paths, ?string $taxCurrency, bool $hasLines, bool $vatWithoutCurrency = false): ?Totals
    {
        $node = $this->node($context, $paths['node']);
        if ($node === null) {
            $this->missingTotals($hasLines);

            return null;
        }

        $c = $this->currency;
        // BT-110 is the VAT total in the invoice currency only; BT-111 the one in the VAT accounting currency.
        $vatTotal = $this->amountIn($node, $paths['vat'], $c);
        if ($vatWithoutCurrency && $vatTotal === null && ($vatTotal = $this->amountIn($node, $paths['vat'], '')) !== null) {
            $this->finding('QUITTIO-READER-CURRENCY', 'The total VAT amount (BT-110) has no currencyID; it was read in the invoice currency.', 'BT-110');
        }
        $vatInTaxCurrency = $taxCurrency !== null && $taxCurrency !== $c
            ? $this->money($this->amountIn($node, $paths['vat'], $taxCurrency), $taxCurrency, 'BT-111')
            : null;

        $allowanceSum = $this->money($this->value($node, $paths['allowance']), $c, 'BT-107');
        $chargeSum = $this->money($this->value($node, $paths['charge']), $c, 'BT-108');
        $netTotal = $this->requiredTotal($node, $paths['net'], 'BT-109', 'BR-13', 'Invoice total amount without VAT');

        return new Totals(
            lineNetSum: $this->lineNetSum($this->money($this->value($node, $paths['lineNet']), $c, 'BT-106'), $hasLines, $netTotal, $allowanceSum, $chargeSum),
            netTotal: $netTotal,
            grossTotal: $this->requiredTotal($node, $paths['gross'], 'BT-112', 'BR-14', 'Invoice total amount with VAT'),
            amountDue: $this->requiredTotal($node, $paths['due'], 'BT-115', 'BR-15', 'Amount due for payment'),
            allowanceSum: $allowanceSum,
            chargeSum: $chargeSum,
            vatTotal: $this->money($vatTotal, $c, 'BT-110'),
            vatTotalInTaxCurrency: $vatInTaxCurrency,
            prepaidAmount: $this->money($this->value($node, $paths['prepaid']), $c, 'BT-113'),
            roundingAmount: $this->money($this->value($node, $paths['rounding']), $c, 'BT-114'),
        );
    }

    /**
     * A required total; missing, it is reported with the rule and read as zero.
     */
    protected function requiredTotal(DOMElement $node, string $path, string $bt, string $rule, string $label): Money
    {
        return $this->money($this->value($node, $path), $this->currency, $bt) ?? $this->missingAmount($bt, $rule, $label);
    }

    /**
     * The declared VAT breakdown (BG-23) among the nodes $paths['node'] of $context.
     *
     * @param  array{node: string, taxable: string, tax: string, category: string, rate: string, reason: string, reasonCode: string}  $paths
     * @return list<VatBreakdown>
     */
    protected function vatBreakdown(DOMElement $context, array $paths): array
    {
        $result = [];
        foreach ($this->nodes($context, $paths['node']) as $node) {
            $result[] = new VatBreakdown(
                taxableAmount: $this->money($this->value($node, $paths['taxable']), $this->currency, 'BT-116') ?? $this->missingAmount('BT-116', 'BR-45', 'VAT category taxable amount'),
                taxAmount: $this->money($this->value($node, $paths['tax']), $this->currency, 'BT-117') ?? $this->missingAmount('BT-117', 'BR-46', 'VAT category tax amount'),
                category: $this->category($this->value($node, $paths['category']), 'BT-118'),
                rate: $this->decimal($this->value($node, $paths['rate']), 'BT-119'),
                exemptionReason: $this->value($node, $paths['reason']),
                exemptionReasonCode: $this->code($this->value($node, $paths['reasonCode']), VatExemptionReasonCode::class, 'BT-121'),
            );
        }

        return $result;
    }

    /**
     * The result of a read: exemption reasons that the breakdown declares are applied to the
     * data, the findings of the read are attached.
     *
     * @param  list<VatBreakdown>  $breakdown
     */
    protected function result(InvoiceData $data, Syntax $syntax, ?string $specificationId, ?Totals $totals, array $breakdown, string $xml): ReadResult
    {
        return new ReadResult(
            data: DeclaredExemptionReasons::apply($data),
            syntax: $syntax->value,
            specificationId: $specificationId,
            profile: null,
            declaredTotals: $totals,
            declaredVatBreakdown: $breakdown,
            findings: $this->findings,
            xml: $xml,
        );
    }

    /**
     * Document level allowances (BG-20) among the nodes $paths['node'] of $parent; $paths names
     * the elements of the syntax relative to one node.
     *
     * @param  array{node: string, indicator: string, amount: string, category: string, rate: string, base: string, percent: string, reason: string, reasonCode: string}  $paths
     * @return list<DocumentAllowance>
     */
    protected function documentAllowances(DOMNode $parent, array $paths): array
    {
        $result = [];
        foreach ($this->nodes($parent, $paths['node']) as $node) {
            if ($this->chargeIndicator($node, $paths['indicator']) !== false) {
                continue;
            }
            $result[] = new DocumentAllowance(
                amount: $this->money($this->value($node, $paths['amount']), $this->currency, 'BT-92'),
                vatCategory: $this->category($this->value($node, $paths['category']), 'BT-95'),
                vatRate: $this->decimal($this->value($node, $paths['rate']), 'BT-96'),
                baseAmount: $this->money($this->value($node, $paths['base']), $this->currency, 'BT-93'),
                percent: $this->decimal($this->value($node, $paths['percent']), 'BT-94'),
                reason: $this->value($node, $paths['reason']),
                reasonCode: $this->code($this->value($node, $paths['reasonCode']), AllowanceReasonCode::class, 'BT-98'),
            );
        }

        return $result;
    }

    /**
     * Document level charges (BG-21), see documentAllowances(). Entries without a valid charge
     * indicator are reported here (and skipped by both).
     *
     * @param  array{node: string, indicator: string, amount: string, category: string, rate: string, base: string, percent: string, reason: string, reasonCode: string}  $paths
     * @return list<DocumentCharge>
     */
    protected function documentCharges(DOMNode $parent, array $paths): array
    {
        $result = [];
        foreach ($this->nodes($parent, $paths['node']) as $node) {
            $isCharge = $this->chargeIndicator($node, $paths['indicator']);
            if ($isCharge === null) {
                $this->invalidChargeIndicator($node, $paths['indicator'], 'BG-20');
            }
            if ($isCharge !== true) {
                continue;
            }
            $result[] = new DocumentCharge(
                amount: $this->money($this->value($node, $paths['amount']), $this->currency, 'BT-99'),
                vatCategory: $this->category($this->value($node, $paths['category']), 'BT-102'),
                vatRate: $this->decimal($this->value($node, $paths['rate']), 'BT-103'),
                baseAmount: $this->money($this->value($node, $paths['base']), $this->currency, 'BT-100'),
                percent: $this->decimal($this->value($node, $paths['percent']), 'BT-101'),
                reason: $this->value($node, $paths['reason']),
                reasonCode: $this->code($this->value($node, $paths['reasonCode']), ChargeReasonCode::class, 'BT-105'),
            );
        }

        return $result;
    }

    /**
     * The VAT category of a code; an unknown code is reported and read as "S".
     */
    protected function category(?string $value, string $bt): VatCategory
    {
        $category = $value === null ? null : VatCategory::tryFrom($value);
        if ($category === null) {
            $this->finding('QUITTIO-READER-VAT-CATEGORY', sprintf('The VAT category code "%s" (%s) is unknown; "S" is assumed.', $value ?? '', $bt), $bt, Severity::Error);

            return VatCategory::StandardRate;
        }

        return $category;
    }

    protected function date(?string $value, string $bt): ?Date
    {
        if ($value === null) {
            return null;
        }

        try {
            return Date::fromIsoDate($value);
        } catch (Throwable) {
            $this->finding('QUITTIO-READER-DATE', sprintf('The date "%s" (%s) is not a valid date in the format YYYY-MM-DD and was not read.', self::excerpt($value), $bt), $bt, Severity::Error);

            return null;
        }
    }

    /**
     * Strict CII date: udt:DateTimeString, udt:DateString or qdt:DateTimeString with format 102 (YYYYMMDD).
     */
    protected function ciiDate(DOMNode $context, string $path, string $bt): ?Date
    {
        $node = $this->node($context, $path);
        if ($node === null) {
            return null;
        }

        $value = trim($node->textContent);
        $format = $node->getAttribute('format');
        if ($value === '' && $format === '') {
            return null;
        }

        try {
            if ($format !== '102') {
                throw new InvalidArgumentException('Unsupported date format.');
            }

            return Date::fromFormat102($value);
        } catch (Throwable) {
            $this->finding('QUITTIO-READER-DATE', sprintf('The date "%s" (%s) with format "%s" is not a valid date in format 102 (YYYYMMDD) and was not read.', self::excerpt($value), $bt, self::excerpt($format)), $bt, Severity::Error);

            return null;
        }
    }

    /**
     * The invoice issue date (BT-2) is mandatory (BR-03). Without a readable date a fixed
     * placeholder is used, never the current date, and an error is reported.
     */
    protected function issueDate(?Date $date): Date
    {
        if ($date !== null) {
            return $date;
        }

        $this->finding('BR-03', sprintf('An invoice shall have an invoice issue date (BT-2). The date is missing or unreadable; the placeholder %s is used.', self::MISSING_DATE), 'BT-2', Severity::Error);

        return Date::fromIsoDate(self::MISSING_DATE);
    }

    protected function decimal(?string $value, string $bt): ?BigDecimal
    {
        if ($value === null) {
            return null;
        }

        // xs:decimal only: no exponents (1e9999999 would expand to millions of digits), no
        // thousands separators, a bounded number of digits.
        if (strlen($value) > self::MAX_NUMBER_LENGTH || ! Decimal::isPlain($value)) {
            $this->finding('QUITTIO-READER-NUMBER', sprintf('The number "%s" (%s) is not a valid decimal number and was not read.', self::excerpt($value), $bt), $bt, Severity::Error);

            return null;
        }

        try {
            return BigDecimal::of($value);
        } catch (MathException) {
            $this->finding('QUITTIO-READER-NUMBER', sprintf('The number "%s" (%s) could not be read.', self::excerpt($value), $bt), $bt, Severity::Error);

            return null;
        }
    }

    protected function money(?string $value, string $currency, string $bt): ?Money
    {
        $decimal = $this->decimal($value, $bt);
        if ($decimal === null) {
            return null;
        }

        if ($decimal->strippedOfTrailingZeros()->getScale() > Amount::SCALE) {
            $rule = self::DECIMAL_RULES[$bt] ?? null;
            $this->finding(
                $rule ?? 'QUITTIO-READER-DECIMALS',
                sprintf('The amount %s (%s) has more than two decimals%s and was rounded.', self::excerpt((string) $value), $bt, $rule === null ? '' : ' ('.$rule.')'),
                $bt,
                $rule === null ? Severity::Warning : Severity::Error,
            );
        }

        return Amount::of($decimal, $currency, round: true);
    }

    /**
     * A mandatory amount is missing: reported as error, zero is used to keep the data complete.
     */
    protected function missingAmount(string $bt, string $rule, string $label): Money
    {
        $this->finding($rule, sprintf('The %s (%s) is missing; 0.00 is used.', lcfirst($label), $bt), $bt, Severity::Error);

        return Amount::zero($this->currency);
    }

    /**
     * The document totals (BG-22) are missing as a whole: their mandatory amounts are reported
     * with the EN 16931 rules (BR-12 for documents with lines, BR-13 to BR-15). The data keeps
     * no totals (InvoiceData::$totals null) instead of invented zero amounts.
     */
    protected function missingTotals(bool $hasLines): void
    {
        $missing = [
            ['BR-12', 'BT-106', 'Sum of invoice line net amounts'],
            ['BR-13', 'BT-109', 'Invoice total amount without VAT'],
            ['BR-14', 'BT-112', 'Invoice total amount with VAT'],
            ['BR-15', 'BT-115', 'Amount due for payment'],
        ];
        foreach ($hasLines ? $missing : array_slice($missing, 1) as [$rule, $bt, $label]) {
            $this->finding($rule, sprintf('The %s (%s) is missing: the document has no document totals (BG-22).', lcfirst($label), $bt), $bt, Severity::Error);
        }
    }

    /**
     * BT-106 is required whenever the document has lines (BR-12). Profiles without lines
     * (Factur-X MINIMUM) do not transmit it; then it is derived from BT-109, BT-107 and
     * BT-108 so that the declared totals stay consistent, and this is reported.
     */
    protected function lineNetSum(?Money $declared, bool $hasLines, Money $netTotal, ?Money $allowanceSum, ?Money $chargeSum): Money
    {
        if ($declared !== null) {
            return $declared;
        }

        if ($hasLines) {
            return $this->missingAmount('BT-106', 'BR-12', 'Sum of invoice line net amounts');
        }

        $derived = $netTotal;
        if ($allowanceSum !== null) {
            $derived = $derived->plus($allowanceSum);
        }
        if ($chargeSum !== null) {
            $derived = $derived->minus($chargeSum);
        }
        $this->finding('QUITTIO-READER-DERIVED', sprintf('The document has no lines and no sum of line net amounts (BT-106); %s was derived from BT-109, BT-107 and BT-108.', Amount::format($derived)), 'BT-106', Severity::Info);

        return $derived;
    }

    /**
     * File names of attached documents (BT-125-2) are never used as paths: directory parts,
     * control and format characters (such as the right-to-left override, which disguises
     * "x\u{202E}fdp.exe" as "xexe.pdf") are removed, "." and ".." and empty names are rejected.
     */
    protected function attachmentFilename(?string $filename): ?string
    {
        if ($filename === null) {
            return null;
        }

        $name = basename(str_replace('\\', '/', $filename));
        $name = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $name));

        return $name === '' || $name === '.' || $name === '..' ? null : $name;
    }

    /**
     * Currency for reading amounts when BT-5 is missing or not an ISO 4217 code: the
     * currency of the amounts if it is a valid code, otherwise XXX ("no currency").
     */
    protected function invoiceCurrency(?string $declared, ?string $fromAmounts): string
    {
        if ($declared !== null && CodeList::contains(CodeList::CURRENCIES, $declared)) {
            return $declared;
        }

        $fallback = $fromAmounts !== null && CodeList::contains(CodeList::CURRENCIES, $fromAmounts) ? $fromAmounts : 'XXX';
        if ($declared === null) {
            $this->finding('BR-05', sprintf('An invoice shall have an invoice currency code (BT-5). The code is missing; the amounts were read in %s.', $fallback), 'BT-5', Severity::Error);
        } else {
            $this->finding('BR-CL-04', sprintf('The invoice currency code (BT-5) "%s" is not an ISO 4217 currency code; the amounts were read in %s.', self::excerpt($declared), $fallback), 'BT-5', Severity::Error);
        }

        return $fallback;
    }

    /**
     * Value of the first amount at $path with the given currencyID. The currency is compared in
     * PHP and never put into the XPath expression: BT-6 is untrusted input.
     */
    protected function amountIn(DOMNode $context, string $path, string $currency): ?string
    {
        foreach ($this->nodes($context, $path) as $node) {
            if (trim($node->getAttribute('currencyID')) === $currency) {
                $value = trim($node->textContent);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    /**
     * Reports amounts whose currencyID differs from the invoice currency (BT-5). Only the
     * total VAT amount in accounting currency (BT-111) may use the VAT accounting currency.
     *
     * @param  list<DOMElement>  $allowedInTaxCurrency
     */
    protected function checkAmountCurrencies(DOMDocument $document, string $currency, ?string $taxCurrency, array $allowedInTaxCurrency): void
    {
        $foreign = [];
        foreach ($this->nodes($document, '//*[@currencyID]') as $node) {
            $value = trim($node->getAttribute('currencyID'));
            if ($value === $currency) {
                continue;
            }
            if ($taxCurrency !== null && $value === $taxCurrency && in_array($node, $allowedInTaxCurrency, true)) {
                continue;
            }
            $foreign['c'.$value] ??= ['currency' => $value, 'count' => 0];
            $foreign['c'.$value]['count']++;
        }

        foreach ($foreign as ['currency' => $value, 'count' => $count]) {
            $this->finding('QUITTIO-READER-CURRENCY', sprintf('%d amount(s) use the currency "%s" instead of the invoice currency %s (BT-5); they were read as %s.', $count, self::excerpt($value), $currency, $currency), 'BT-5', Severity::Error);
        }
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|RawCode|null
     */
    protected function code(?string $value, string $enum, string $bt): BackedEnum|RawCode|null
    {
        if ($value === null) {
            return null;
        }

        $case = $enum::tryFrom($value);
        if ($case !== null) {
            return $case;
        }

        // Codes of the official code list without an enum case (e.g. most VATEX codes) are valid.
        if (method_exists($enum, 'isValidCode') && $enum::isValidCode($value) === true) {
            return new RawCode($value);
        }

        $this->finding('QUITTIO-READER-UNKNOWN-CODE', sprintf('The code "%s" (%s) is not known to this package version and was kept as raw value.', $value, $bt), $bt, Severity::Info);

        return new RawCode($value);
    }

    protected function finding(string $code, string $message, ?string $bt = null, Severity $severity = Severity::Warning): void
    {
        $this->findings[] = new ValidationMessage($severity, $code, $message, ValidationLevel::L1, $bt);
    }

    /**
     * Shortens untrusted values for messages.
     */
    protected static function excerpt(string $value): string
    {
        return mb_strlen($value) > 64 ? mb_substr($value, 0, 64).'…' : $value;
    }
}
