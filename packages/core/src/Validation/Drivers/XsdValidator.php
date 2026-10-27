<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Drivers;

use DOMDocument;
use LibXMLError;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationDriver;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Core\Xml\UnsafeXmlException;
use Quittio\Core\Xml\XmlSecurity;

/**
 * XML schema validation (level L4) with the bundled CII D16B and UBL 2.1 schemas. XML of another
 * syntax read by the reader of a country pack (e.g. ebInterface) is not checked here: the reader
 * checks its structure.
 */
final class XsdValidator implements ValidationDriver
{
    private readonly string $schemaPath;

    public function __construct(?string $schemaPath = null)
    {
        $this->schemaPath = $schemaPath ?? dirname(__DIR__, 3).'/resources/xsd';
    }

    /**
     * Returns "xsd", the name of this check in the validation.levels configuration.
     */
    public function name(): string
    {
        return 'xsd';
    }

    public function levels(): array
    {
        return [ValidationLevel::L4];
    }

    public function supports(ValidationSubject $subject): bool
    {
        if ($subject->xml === null) {
            return false;
        }
        if ($subject->syntax !== null) {
            return true;
        }
        try {
            return $this->schemaFor(XmlSecurity::load($subject->xml)) !== null;
        } catch (UnsafeXmlException) {
            return true;   // reported as XSD-NOT-WELL-FORMED
        }
    }

    /**
     * Validates the subject's XML against the schema of its root element and returns the schema
     * errors as level L4, e.g. XSD-NOT-WELL-FORMED for unreadable or unsafe XML and
     * XSD-UNKNOWN-ROOT when no schema fits.
     */
    public function validate(ValidationSubject $subject): ValidationResult
    {
        return new ValidationResult($this->validateXml($subject->xml ?? ''), [ValidationLevel::L4]);
    }

    /**
     * @return list<ValidationMessage>
     */
    public function validateXml(string $xml): array
    {
        try {
            $document = XmlSecurity::load($xml);
        } catch (UnsafeXmlException $e) {
            return [$this->message('XSD-NOT-WELL-FORMED', $e->getMessage(), null)];
        }

        $schema = $this->schemaFor($document);
        if ($schema === null) {
            return [$this->message('XSD-UNKNOWN-ROOT', sprintf('No schema is known for the root element "%s".', $document->documentElement?->localName), null)];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document->schemaValidate($schema, LIBXML_SCHEMA_CREATE);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return array_map(
            fn (LibXMLError $error): ValidationMessage => $this->message('XSD', sprintf('Line %d: %s', $error->line, trim($error->message)), $error->line),
            array_values(array_filter($errors, static fn (LibXMLError $e): bool => $e->level >= LIBXML_ERR_ERROR)),
        );
    }

    /**
     * Path of the bundled XSD for the document's root element (CII D16B CrossIndustryInvoice, UBL
     * 2.1 Invoice or CreditNote); null for any other root element.
     */
    public function schemaFor(DOMDocument $document): ?string
    {
        $root = $document->documentElement;

        return match ([$root?->namespaceURI, $root?->localName]) {
            ['urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100', 'CrossIndustryInvoice'] => $this->schemaPath.'/cii-d16b/CrossIndustryInvoice_100pD16B.xsd',
            ['urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'Invoice'] => $this->schemaPath.'/ubl-2.1/maindoc/UBL-Invoice-2.1.xsd',
            ['urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2', 'CreditNote'] => $this->schemaPath.'/ubl-2.1/maindoc/UBL-CreditNote-2.1.xsd',
            default => null,
        };
    }

    /**
     * Syntax of the document by its root element name: CII for CrossIndustryInvoice, UBL for
     * Invoice and CreditNote; null for any other root element. The namespace is not checked.
     */
    public static function syntaxOf(DOMDocument $document): ?Syntax
    {
        return match ($document->documentElement?->localName) {
            'CrossIndustryInvoice' => Syntax::Cii,
            'Invoice', 'CreditNote' => Syntax::Ubl,
            default => null,
        };
    }

    private function message(string $code, string $text, ?int $line): ValidationMessage
    {
        return new ValidationMessage(
            severity: Severity::Error,
            code: $code,
            message: $text,
            level: ValidationLevel::L4,
            path: $line === null ? null : 'line '.$line,
        );
    }
}
