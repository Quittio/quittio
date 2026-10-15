<?php

declare(strict_types=1);

namespace Quittio\Core\Xml;

use DOMDocument;
use DOMElement;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Exceptions\RuntimeException;

/**
 * Builds XML documents element by element.
 *
 * Empty values are skipped and containers without content are removed again,
 * so that no empty elements are written. The output is
 * deterministic: same input, same bytes.
 *
 * @internal
 */
final class XmlBuilder
{
    /**
     * Matches a character outside the Char production of XML 1.0 (e.g. the vertical tab of spreadsheet
     * cells); preg_match() fails on invalid UTF-8.
     */
    private const string NON_XML_CHARACTER = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

    private DOMDocument $document;

    /** @var list<DOMElement> */
    private array $stack = [];

    /** @var array<string, string> */
    private array $namespaces = [];

    private ?string $defaultNamespace = null;

    public function __construct(bool $indent = true)
    {
        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = $indent;
        $this->document->preserveWhiteSpace = false;
    }

    /**
     * @param  array<string, string>  $namespaces  prefix => URI
     */
    public function root(string $name, array $namespaces, ?string $defaultNamespace = null): self
    {
        $this->namespaces = $namespaces;
        $this->defaultNamespace = $defaultNamespace;

        $root = $this->create($name);
        foreach ($namespaces as $prefix => $uri) {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:'.$prefix, $uri);
        }
        $this->document->appendChild($root);
        $this->stack = [$root];

        return $this;
    }

    /**
     * Whether a string is valid UTF-8 and contains only characters allowed in XML 1.0.
     */
    public static function isXmlText(string $value): bool
    {
        return preg_match(self::NON_XML_CHARACTER, $value) === 0;
    }

    /**
     * Writes an element with text content. Null or empty values are skipped.
     *
     * @param  array<string, string|null>  $attributes
     *
     * @throws InvalidInvoiceData when a value contains characters that XML 1.0 cannot represent
     */
    public function element(string $name, ?string $value, array $attributes = []): self
    {
        if ($value === null || $value === '') {
            return $this;
        }

        // DOMDocument would write them unchanged and produce a document that is not well-formed.
        if (! self::isXmlText($value)) {
            throw InvalidInvoiceData::invalidXmlCharacters($name);
        }
        $element = $this->create($name);
        $element->appendChild($this->document->createTextNode($value));
        foreach ($attributes as $attribute => $attributeValue) {
            if ($attributeValue !== null && $attributeValue !== '') {
                if (! self::isXmlText($attributeValue)) {
                    throw InvalidInvoiceData::invalidXmlCharacters($name.'/@'.$attribute);
                }
                $element->setAttribute($attribute, $attributeValue);
            }
        }
        $this->current()->appendChild($element);

        return $this;
    }

    /**
     * Writes a container that is removed again if the callback adds no content.
     *
     * @param  callable(self): mixed  $content
     */
    public function group(string $name, callable $content): self
    {
        $element = $this->open($name, $content);
        if (! $element->hasChildNodes()) {
            $this->current()->removeChild($element);
        }

        return $this;
    }

    /**
     * Writes a mandatory container, even if it stays empty.
     *
     * @param  callable(self): mixed  $content
     */
    public function required(string $name, callable $content): self
    {
        $this->open($name, $content);

        return $this;
    }

    public function xml(): string
    {
        $xml = $this->document->saveXML();
        if ($xml === false) {
            throw new RuntimeException('Unable to serialise XML.');
        }

        return $xml;
    }

    /**
     * @param  callable(self): mixed  $content
     */
    private function open(string $name, callable $content): DOMElement
    {
        $element = $this->create($name);
        $this->current()->appendChild($element);
        $this->stack[] = $element;
        $content($this);
        array_pop($this->stack);

        return $element;
    }

    private function current(): DOMElement
    {
        return $this->stack[count($this->stack) - 1] ?? throw new RuntimeException('No root element.');
    }

    private function create(string $name): DOMElement
    {
        $prefix = str_contains($name, ':') ? strstr($name, ':', true) : null;
        $uri = $prefix === null ? $this->defaultNamespace : ($this->namespaces[$prefix] ?? null);
        if ($prefix !== null && $uri === null) {
            throw new InvalidArgumentException(sprintf('Unknown namespace prefix "%s".', $prefix));
        }

        return $uri === null ? $this->document->createElement($name) : $this->document->createElementNS($uri, $name);
    }
}
