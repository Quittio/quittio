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
use InvalidArgumentException;
use Quittio\Core\Codes\CodeList;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Throwable;

/**
 * Base class for XML readers: XPath access, tolerant value conversion and findings.
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
