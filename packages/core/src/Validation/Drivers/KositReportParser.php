<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Drivers;

use DOMElement;
use DOMXPath;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Xml\XmlSecurity;

/**
 * Converts a KoSIT validator report (VARL) into validation messages.
 */
final class KositReportParser
{
    public const string NS_REPORT = 'http://www.xoev.de/de/validator/varl/1';

    public const string NS_SCENARIOS = 'http://www.xoev.de/de/validator/framework/1/scenarios';

    /**
     * @return array{accepted: bool, scenario: ?string, messages: list<ValidationMessage>}
     */
    public function parse(string $report): array
    {
        $document = XmlSecurity::load($report, 64 * 1024 * 1024);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('rep', self::NS_REPORT);
        $xpath->registerNamespace('s', self::NS_SCENARIOS);

        $accept = $xpath->query('/rep:report/rep:assessment/rep:accept');
        $accepted = $accept !== false && $accept->length > 0;
        $names = $xpath->query('/rep:report/rep:scenarioMatched/s:scenario/s:name');
        $scenarioNode = $names === false ? null : $names->item(0);
        $scenario = $scenarioNode instanceof DOMElement ? trim($scenarioNode->textContent) : null;

        // The scenario can change the level of single rules (e.g. the XRechnung extension lowers
        // BR-CO-16 to information); the validator decides on acceptance with these levels, while
        // the messages keep the level of the Schematron.
        $customLevels = [];
        $levels = $xpath->query('/rep:report/rep:scenarioMatched/s:scenario/s:createReport/s:customLevel');
        foreach ($levels === false ? [] : $levels as $level) {
            if ($level instanceof DOMElement) {
                $customLevels[trim($level->textContent)] = $level->getAttribute('level');
            }
        }

        $messages = [];
        $nodes = $xpath->query('//rep:message');
        foreach ($nodes === false ? [] : $nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $code = $node->getAttribute('code');
            $text = trim(preg_replace('/^\[[^\]]+\]\s*-?\s*/', '', trim($node->textContent)) ?? $node->textContent);
            $step = $node->parentNode instanceof DOMElement ? $node->parentNode->getAttribute('id') : '';
            $xpathLocation = $node->getAttribute('xpathLocation');

            $messages[] = new ValidationMessage(
                severity: match ($customLevels[$code] ?? $node->getAttribute('level')) {
                    'error' => Severity::Error,
                    'warning' => Severity::Warning,
                    default => Severity::Info,
                },
                code: $code !== '' ? $code : ($step === 'val-xsd' ? 'KOSIT-XSD' : 'KOSIT'),
                message: $text,
                level: $step === 'val-xsd' ? ValidationLevel::L4 : ValidationLevel::L5,
                bt: preg_match('/\b(BT-\d+|BG-\d+)\b/', $text, $m) === 1 ? $m[1] : null,
                xpath: $xpathLocation === '' ? null : $xpathLocation,
            );
        }

        if ($scenario === null) {
            $messages[] = new ValidationMessage(
                severity: Severity::Warning,
                code: 'KOSIT-NO-SCENARIO',
                message: 'The validator configuration contains no scenario for this document type (e.g. Factur-X EXTENDED). Only the XML schema was checked.',
                level: ValidationLevel::L5,
            );
        } elseif (! $accepted && array_filter($messages, static fn (ValidationMessage $m): bool => $m->isError()) === []) {
            $messages[] = new ValidationMessage(Severity::Error, 'KOSIT-REJECT', 'The validator recommends rejecting the document.', ValidationLevel::L5);
        }

        return ['accepted' => $accepted, 'scenario' => $scenario, 'messages' => $messages];
    }
}
