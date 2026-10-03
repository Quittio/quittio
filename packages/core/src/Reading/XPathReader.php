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
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Throwable;

/**
 * Base class for XML readers: XPath access, tolerant value conversion and findings.
 */
abstract class XPathReader
{
    protected DOMXPath $xpath;

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

    protected function date(?string $value, string $bt): ?Date
    {
        if ($value === null) {
            return null;
        }

        try {
            return Date::parse($value);
        } catch (Throwable) {
            $this->finding('QUITTIO-READER-DATE', sprintf('The date "%s" (%s) could not be read.', $value, $bt), $bt, Severity::Error);

            return null;
        }
    }

    protected function decimal(?string $value, string $bt): ?BigDecimal
    {
        if ($value === null) {
            return null;
        }

        try {
            return BigDecimal::of($value);
        } catch (MathException) {
            $this->finding('QUITTIO-READER-NUMBER', sprintf('The number "%s" (%s) could not be read.', $value, $bt), $bt, Severity::Error);

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
            $this->finding('QUITTIO-READER-DECIMALS', sprintf('The amount %s (%s) has more than two decimals and was rounded.', $value, $bt), $bt, Severity::Warning);
        }

        return Amount::of($decimal, $currency, round: true);
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

        $this->finding('QUITTIO-READER-UNKNOWN-CODE', sprintf('The code "%s" (%s) is not known to this package version and was kept as raw value.', $value, $bt), $bt, Severity::Info);

        return new RawCode($value);
    }

    protected function finding(string $code, string $message, ?string $bt = null, Severity $severity = Severity::Warning): void
    {
        $this->findings[] = new ValidationMessage($severity, $code, $message, ValidationLevel::L1, $bt);
    }
}
