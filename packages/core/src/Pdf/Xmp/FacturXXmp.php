<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Xmp;

use Quittio\Core\Pdf\EmbeddedXml;

/**
 * XMP metadata for Factur-X/ZUGFeRD: the fx description and the PDF/A extension schema.
 * Without the extension schema the PDF is readable, but not valid PDF/A.
 */
final class FacturXXmp
{
    public const string NAMESPACE = 'urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#';

    public static function forEmbedded(EmbeddedXml $embedded): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $properties = [
            ['DocumentFileName', 'The name of the embedded XML document'],
            ['DocumentType', 'The type of the hybrid document in capital letters, e.g. INVOICE or ORDER'],
            ['Version', 'The actual version of the standard applying to the embedded XML document'],
            ['ConformanceLevel', 'The conformance level of the embedded XML document'],
        ];

        $schemaProperties = '';
        foreach ($properties as [$name, $description]) {
            $schemaProperties .= <<<XML
                <rdf:li rdf:parseType="Resource">
                  <pdfaProperty:name>{$name}</pdfaProperty:name>
                  <pdfaProperty:valueType>Text</pdfaProperty:valueType>
                  <pdfaProperty:category>external</pdfaProperty:category>
                  <pdfaProperty:description>{$description}</pdfaProperty:description>
                </rdf:li>

XML;
        }

        $namespace = self::NAMESPACE;
        $conformance = $embedded->conformanceLevel === null ? '' : '    <fx:ConformanceLevel>'.$e($embedded->conformanceLevel)."</fx:ConformanceLevel>\n";

        return <<<XML
   <rdf:Description rdf:about="" xmlns:fx="{$namespace}">
    <fx:DocumentType>{$e($embedded->documentType)}</fx:DocumentType>
    <fx:DocumentFileName>{$e($embedded->filename)}</fx:DocumentFileName>
    <fx:Version>{$e($embedded->version)}</fx:Version>
{$conformance}   </rdf:Description>
   <rdf:Description rdf:about=""
       xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/"
       xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#"
       xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">
    <pdfaExtension:schemas>
     <rdf:Bag>
      <rdf:li rdf:parseType="Resource">
       <pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>
       <pdfaSchema:namespaceURI>{$namespace}</pdfaSchema:namespaceURI>
       <pdfaSchema:prefix>fx</pdfaSchema:prefix>
       <pdfaSchema:property>
        <rdf:Seq>
{$schemaProperties}        </rdf:Seq>
       </pdfaSchema:property>
      </rdf:li>
     </rdf:Bag>
    </pdfaExtension:schemas>
   </rdf:Description>

XML;
    }
}
