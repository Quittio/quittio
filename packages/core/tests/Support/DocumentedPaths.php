<?php

declare(strict_types=1);

namespace Quittio\Core\Tests\Support;

use DOMDocument;
use DOMXPath;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Mapping\FieldMatrix;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;
use UnitEnum;

/**
 * Checks the informative #[Cii] and #[Ubl] paths of the mapping attributes against written XML:
 * every field with a value must find a node with one of its documented paths.
 */
final class DocumentedPaths
{
    private const array CII = [
        'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
        'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
        'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
        'qdt' => 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100',
    ];

    private const array UBL = [
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
    ];

    /**
     * Fields of $data whose documented path finds nothing in the CII written with $cii and the
     * UBL written with $ubl (null = not checked), as "field syntax" => path.
     *
     * @return array<string, string>
     */
    public static function missing(InvoiceData $data, Profile $cii, ?Profile $ubl): array
    {
        $ciiXPath = self::xpath((new CiiWriter)->serialize($data, $cii), self::CII);
        $ublXPath = $ubl === null ? null : self::xpath((new UblWriter)->serialize($data, $ubl), self::UBL);

        $missing = [];
        foreach (self::fields($data) as $field) {
            if ($field['cii'] !== null && ! self::exists($ciiXPath, $field['cii'])) {
                $missing[$field['field'].' CII'] = $field['cii'];
            }
            if ($ublXPath !== null && $field['ubl'] !== null && ! $field['extendedOnly'] && ! self::exists($ublXPath, $field['ubl'])) {
                $missing[$field['field'].' UBL'] = $field['ubl'];
            }
        }

        return $missing;
    }

    /**
     * The fields of $object (and its parts) that have a value and a documented path.
     *
     * @return list<array{field: string, cii: ?string, ubl: ?string, extendedOnly: bool}>
     */
    public static function fields(object $object, string $prefix = ''): array
    {
        $fields = [];
        foreach (FieldMatrix::fields($object::class) as $name => $field) {
            $value = $object->{$name} ?? null;
            if ($value === null || $value === [] || $value === '' || is_bool($value) || $field->pdfOnly || $field->internal || $field->xrechnungExtensionOnly) {
                continue;
            }
            if ($field->cii !== null || $field->ubl !== null) {
                $fields[] = ['field' => $prefix.$name.(is_array($value) ? '[]' : ''), 'cii' => $field->cii, 'ubl' => $field->ubl, 'extendedOnly' => $field->extendedOnly];
            }
            foreach (is_array($value) ? $value : [$value] as $part) {
                if (is_object($part) && ! $part instanceof UnitEnum && str_starts_with($part::class, 'Quittio\\Core\\Data\\') && FieldMatrix::fields($part::class) !== []) {
                    $fields = [...$fields, ...self::fields($part, $prefix.$name.(is_array($value) ? '[].' : '.'))];
                }
            }
        }

        return $fields;
    }

    /**
     * Whether one of the alternatives of a documented path ("a | b"; a note in parentheses at
     * the end is ignored) finds a node anywhere in the document.
     */
    public static function exists(DOMXPath $xpath, string $path): bool
    {
        foreach (explode(' | ', $path) as $alternative) {
            $expression = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $alternative));
            $nodes = @$xpath->query('//'.$expression);
            if ($nodes !== false && $nodes->length > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $namespaces
     */
    private static function xpath(string $xml, array $namespaces): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        foreach ($namespaces as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return $xpath;
    }
}
