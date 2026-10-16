<?php

declare(strict_types=1);

namespace Quittio\Core\Codes;

use Quittio\Core\Exceptions\RuntimeException;

/**
 * Generates the code list files (resources/codelists/*.json) from the official EN 16931
 * Schematron rules (BR-CL-*), as shipped with the KoSIT validator configuration.
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
     * Generates the Peppol code lists (PEPPOL-EN16931-CL…) that are narrower than the EN 16931
     * lists, from the Peppol BIS Billing 3.0 Schematron (PEPPOL-EN16931-UBL.sch).
     *
     * @return array<string, int> code list name => number of codes
     */
    public function generatePeppol(string $schematronPath, ?string $target = null): array
    {
        $content = is_file($schematronPath) ? file_get_contents($schematronPath) : false;
        if ($content === false) {
            throw new RuntimeException(sprintf('The Peppol Schematron "%s" could not be read.', $schematronPath));
        }
        if (preg_match('/<let name="eaid" value="tokenize\(\'([^\']*)\'/', $content, $list) !== 1) {
            throw new RuntimeException(sprintf('The Peppol Schematron "%s" defines no code list "eaid".', $schematronPath));
        }

        $parts = preg_split('/\s+/', trim($list[1]));
        $codes = array_values(array_unique($parts === false ? [] : $parts));
        sort($codes, SORT_STRING);
        preg_match('/release ([0-9]+(?:\.[0-9]+)*)/', $content, $version);
        preg_match('/id="PEPPOL-EN16931-CL008".*?>([^<]+)<\/assert>/s', $content, $message);

        $target ??= self::defaultTarget();
        $data = [
            'name' => CodeList::PEPPOL_EAS,
            'rule' => 'PEPPOL-EN16931-CL008',
            'description' => trim($message[1] ?? ''),
            'source' => 'Peppol BIS Billing 3.0 Schematron '.($version[1] ?? 'unknown'),
            'codes' => $codes,
        ];
        file_put_contents($target.'/'.CodeList::PEPPOL_EAS.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return [CodeList::PEPPOL_EAS => count($codes)];
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
