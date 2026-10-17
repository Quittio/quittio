<?php

declare(strict_types=1);

namespace Quittio\Core\Codes;

use Quittio\Core\Exceptions\RuntimeException;

/**
 * Generates the code list files (resources/codelists/*.json) from the official EN 16931
 * Schematron rules (BR-CL-*), as shipped with the KoSIT validator configuration, and the
 * narrower Peppol lists from the Peppol BIS Schematron.
 */
final class CodeListGenerator
{
    /** Rule codes and the code list names used by the package. */
    public const array LISTS = [
        'BR-CL-01' => 'invoice-type-codes',
        'BR-CL-03' => 'currency-codes',
        'BR-CL-06' => 'tax-point-date-codes-cii',
        'BR-CL-07' => 'object-identifier-schemes',
        'BR-CL-08' => 'note-subject-codes',
        'BR-CL-10' => 'icd-schemes',
        'BR-CL-13' => 'item-classification-schemes',
        'BR-CL-14' => 'country-codes',
        'BR-CL-16' => 'payment-means-codes',
        'BR-CL-17' => 'vat-category-codes',
        'BR-CL-19' => 'allowance-reason-codes',
        'BR-CL-20' => 'charge-reason-codes',
        'BR-CL-22' => 'vatex-codes',
        'BR-CL-23' => 'unit-codes',
        'BR-CL-24' => 'mime-codes',
        'BR-CL-25' => 'eas-codes',
    ];

    /** Code list names and the Peppol rules they are generated from (generatePeppol()). */
    public const array PEPPOL_LISTS = [
        CodeList::PEPPOL_EAS => 'PEPPOL-EN16931-CL008',
        CodeList::PEPPOL_CURRENCIES => 'PEPPOL-EN16931-CL007',
        CodeList::PEPPOL_INVOICE_TYPES => 'PEPPOL-EN16931-P0100',
        CodeList::PEPPOL_CREDIT_NOTE_TYPES => 'PEPPOL-EN16931-P0101',
    ];

    public static function defaultTarget(): string
    {
        return dirname(__DIR__, 2).'/resources/codelists';
    }

    /**
     * @return array<string, int> code list name => number of codes
     */
    public function generate(string $xslPath, ?string $target = null): array
    {
        $content = is_file($xslPath) ? file_get_contents($xslPath) : false;
        if ($content === false) {
            throw new RuntimeException(sprintf('The Schematron XSL "%s" could not be read.', $xslPath));
        }

        // The rule version is documented in the scenario file of the validator configuration.
        $scenarioFile = dirname($xslPath, 5).'/scenarios.xml';
        $scenarios = is_file($scenarioFile) ? (string) file_get_contents($scenarioFile) : '';
        preg_match('/urn:cen.eu:en16931:2017\s+in version ([0-9.]+) from ([0-9-]+)/', $scenarios, $version);

        $target ??= self::defaultTarget();
        if (! is_dir($target) && ! mkdir($target, 0775, true) && ! is_dir($target)) {
            throw new RuntimeException(sprintf('Unable to create "%s".', $target));
        }

        $counts = [];
        foreach (self::LISTS as $rule => $name) {
            $codes = $this->codes($content, $rule, $name);
            if ($codes === null) {
                continue;
            }

            preg_match('/\['.preg_quote($rule, '/').'\]-([^<]+)</', $content, $message);
            $data = [
                'name' => $name,
                'rule' => $rule,
                'description' => trim($message[1] ?? ''),
                'source' => 'EN 16931 Schematron (CII) '.($version[1] ?? 'unknown').' from '.($version[2] ?? 'unknown'),
                'codes' => $codes,
            ];
            file_put_contents($target.'/'.$name.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $counts[$name] = count($codes);
        }

        return $counts;
    }

    /**
     * Generates the Peppol code lists (PEPPOL-EN16931-CL…, P01…) that are narrower than the
     * EN 16931 lists, from the Peppol BIS Billing 3.0 Schematron (PEPPOL-EN16931-UBL.sch). The
     * codes are read from the assertion of the rule: an inline tokenize('…') or the variable
     * (<let>) the assertion refers to.
     *
     * @return array<string, int> code list name => number of codes
     */
    public function generatePeppol(string $schematronPath, ?string $target = null): array
    {
        $content = is_file($schematronPath) ? file_get_contents($schematronPath) : false;
        if ($content === false) {
            throw new RuntimeException(sprintf('The Peppol Schematron "%s" could not be read.', $schematronPath));
        }
        preg_match('/release ([0-9]+(?:\.[0-9]+)*)/', $content, $version);

        $target ??= self::defaultTarget();
        $counts = [];
        foreach (self::PEPPOL_LISTS as $name => $rule) {
            if (preg_match('/<assert\s+id="'.preg_quote($rule, '/').'"\s+test="([^"]*)"[^>]*>([^<]*)<\/assert>/', $content, $assert) !== 1) {
                throw new RuntimeException(sprintf('The Peppol Schematron "%s" defines no rule "%s".', $schematronPath, $rule));
            }
            $list = null;
            if (preg_match("/tokenize\\('([^']*)'/", $assert[1], $inline) === 1) {
                $list = $inline[1];
            } elseif (preg_match('/\bin\s+\$([A-Za-z0-9_]+)/', $assert[1], $variable) === 1
                && preg_match('/<let name="'.preg_quote($variable[1], '/').'" value="tokenize\(\'([^\']*)\'/', $content, $let) === 1) {
                $list = $let[1];
            }
            if ($list === null) {
                throw new RuntimeException(sprintf('The rule "%s" of the Peppol Schematron "%s" names no code list.', $rule, $schematronPath));
            }

            $parts = preg_split('/\s+/', trim($list));
            $codes = array_values(array_unique($parts === false ? [] : $parts));
            sort($codes, SORT_STRING);
            $data = [
                'name' => $name,
                'rule' => $rule,
                'description' => trim(preg_replace('/\s+/', ' ', $assert[2]) ?? ''),
                'source' => 'Peppol BIS Billing 3.0 Schematron '.($version[1] ?? 'unknown'),
                'codes' => $codes,
            ];
            file_put_contents($target.'/'.$name.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $counts[$name] = count($codes);
        }

        return $counts;
    }

    /**
     * @return list<string>|null
     */
    private function codes(string $content, string $rule, string $name): ?array
    {
        $pos = strpos($content, '['.$rule.']');
        if ($pos === false) {
            return null;
        }

        // The test expression precedes the message text inside the same assertion.
        $start = (int) strrpos(substr($content, 0, $pos), '<svrl:failed-assert');
        $block = substr($content, $start, $pos - $start);
        $whenStart = (int) strrpos(substr($content, 0, $start), '<xsl:when test="');
        $otherwise = (int) strrpos(substr($content, 0, $start), '<xsl:choose>');
        $from = max($whenStart, $otherwise);
        $expression = html_entity_decode(substr($content, $from, $start - $from).$block, ENT_QUOTES | ENT_XML1);

        $codes = [];
        if (preg_match_all("/contains\\('\\s([^']+?)\\s',\\s*concat/", $expression, $matches) > 0) {
            foreach ($matches[1] as $list) {
                $parts = preg_split('/\s+/', trim($list));
                foreach ($parts === false ? [] : $parts as $code) {
                    $codes[$code] = true;
                }
            }
        }
        if ($codes === [] && preg_match_all("/'([^' ]+)'/", $expression, $matches) > 0) {
            foreach ($matches[1] as $code) {
                if (preg_match('/^[A-Za-z0-9.\-\/+]+$/', $code) === 1 && ! str_contains($code, 'svrl')) {
                    $codes[$code] = true;
                }
            }
        }

        // array_keys() turns numeric codes into integers; code values are always strings.
        $codes = array_map(strval(...), array_keys($codes));
        if ($name === 'mime-codes') {
            $codes = array_values(array_filter($codes, static fn (string $code): bool => str_contains($code, '/')));
        }
        sort($codes, SORT_STRING);

        return $codes;
    }
}
