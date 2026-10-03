<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use BackedEnum;
use Closure;
use Quittio\Core\Codes\CodeList;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Profiles\Capabilities\ExtendsCodeLists;
use Quittio\Core\Validation\Rule;

/**
 * Code list rules of EN 16931 (BR-CL-…), based on the official code lists.
 */
final class CodeListRules
{
    /**
     * @return list<Rule>
     */
    public static function all(): array
    {
        return [
            self::rule('BR-CL-01', 'The document type code (BT-3) ":value" is not part of the invoice and credit note related code lists of UNTDID 1001.', CodeList::INVOICE_TYPES,
                static fn (InvoiceData $i): array => ['typeCode' => ['BT-3', $i->typeCode]]),
            self::rule('BR-CL-04', 'The invoice currency code (BT-5) ":value" is not an ISO 4217 currency code.', CodeList::CURRENCIES,
                static fn (InvoiceData $i): array => ['currency' => ['BT-5', $i->currency]]),
            self::rule('BR-CL-05', 'The VAT accounting currency code (BT-6) ":value" is not an ISO 4217 currency code.', CodeList::CURRENCIES,
                static fn (InvoiceData $i): array => ['taxCurrency' => ['BT-6', $i->taxCurrency]]),
            self::rule('BR-CL-07', 'The object identifier scheme ":value" is not part of UNTDID 1153.', CodeList::OBJECT_IDENTIFIERS,
                static function (InvoiceData $i): array {
                    $codes = ['invoicedObject.scheme' => ['BT-18-1', $i->invoicedObject?->scheme]];
                    foreach ($i->allLines() as $line) {
                        $codes["line {$line->id}.objectId.scheme"] = ['BT-128-1', $line->objectId?->scheme];
                    }

                    return $codes;
                }),
            self::rule('BR-CL-08', 'The invoice note subject code (BT-21) ":value" is not part of UNTDID 4451.', CodeList::NOTE_SUBJECTS,
                static function (InvoiceData $i): array {
                    $codes = [];
                    foreach ($i->notes as $index => $note) {
                        $codes["notes[{$index}].subjectCode"] = ['BT-21', $note->subjectCode];
                    }

                    return $codes;
                }),
            self::rule('BR-CL-10', 'The identifier scheme ":value" is not part of ISO/IEC 6523.', CodeList::ICD,
                static function (InvoiceData $i): array {
                    $codes = ['buyer.identifier.scheme' => ['BT-46-1', $i->buyer->identifier?->scheme]];
                    foreach ($i->seller->identifiers as $index => $identifier) {
                        $codes["seller.identifiers[{$index}].scheme"] = ['BT-29-1', $identifier->scheme];
                    }
                    $codes['payee.identifier.scheme'] = ['BT-60-1', $i->payee?->identifier?->scheme];

                    return $codes;
                }),
            self::rule('BR-CL-11', 'The legal registration identifier scheme ":value" is not part of ISO/IEC 6523.', CodeList::ICD,
                static fn (InvoiceData $i): array => [
                    'seller.legalRegistration.scheme' => ['BT-30-1', $i->seller->legalRegistration?->scheme],
                    'buyer.legalRegistration.scheme' => ['BT-47-1', $i->buyer->legalRegistration?->scheme],
                    'payee.legalRegistration.scheme' => ['BT-61-1', $i->payee?->legalRegistration?->scheme],
                ]),
            self::rule('BR-CL-13', 'The item classification scheme ":value" is not part of UNTDID 7143.', CodeList::ITEM_CLASSIFICATIONS,
                static function (InvoiceData $i): array {
                    $codes = [];
                    foreach ($i->allLines() as $line) {
                        foreach ($line->item->classifications as $index => $classification) {
                            $codes["line {$line->id}.item.classifications[{$index}].listId"] = ['BT-158-1', $classification->listId];
                        }
                    }

                    return $codes;
                }),
            self::rule('BR-CL-14', 'The country code ":value" is not an ISO 3166-1 alpha-2 code.', CodeList::COUNTRIES,
                static function (InvoiceData $i): array {
                    $codes = [
                        'seller.address.countryCode' => ['BT-40', $i->seller->address->countryCode],
                        'buyer.address.countryCode' => ['BT-55', $i->buyer->address->countryCode],
                        'sellerTaxRepresentative.address.countryCode' => ['BT-69', $i->sellerTaxRepresentative?->address->countryCode],
                        'delivery.address.countryCode' => ['BT-80', $i->delivery?->address?->countryCode],
                    ];
                    foreach ($i->allLines() as $line) {
                        $codes["line {$line->id}.item.originCountry"] = ['BT-159', $line->item->originCountry];
                    }

                    return $codes;
                }),
            self::rule('BR-CL-16', 'The payment means code (BT-81) ":value" is not part of UNTDID 4461.', CodeList::PAYMENT_MEANS,
                static fn (InvoiceData $i): array => ['paymentInstructions.meansCode' => ['BT-81', $i->paymentInstructions?->meansCode]]),
            self::rule('BR-CL-19', 'The allowance reason code ":value" is not part of UNTDID 5189.', CodeList::ALLOWANCE_REASONS,
                static function (InvoiceData $i): array {
                    $codes = [];
                    foreach ($i->allowances as $index => $allowance) {
                        $codes["allowances[{$index}].reasonCode"] = ['BT-98', $allowance->reasonCode];
                    }
                    foreach ($i->allLines() as $line) {
                        foreach ($line->allowances as $index => $allowance) {
                            $codes["line {$line->id}.allowances[{$index}].reasonCode"] = ['BT-140', $allowance->reasonCode];
                        }
                    }

                    return $codes;
                }),
            self::rule('BR-CL-20', 'The charge reason code ":value" is not part of UNTDID 7161.', CodeList::CHARGE_REASONS,
                static function (InvoiceData $i): array {
                    $codes = [];
                    foreach ($i->charges as $index => $charge) {
                        $codes["charges[{$index}].reasonCode"] = ['BT-105', $charge->reasonCode];
                    }
                    foreach ($i->allLines() as $line) {
                        foreach ($line->charges as $index => $charge) {
                            $codes["line {$line->id}.charges[{$index}].reasonCode"] = ['BT-145', $charge->reasonCode];
                        }
                    }

                    return $codes;
                }),
            self::rule('BR-CL-21', 'The item standard identifier scheme ":value" is not part of ISO/IEC 6523.', CodeList::ICD,
                static function (InvoiceData $i): array {
                    $codes = [];
                    foreach ($i->allLines() as $line) {
                        $codes["line {$line->id}.item.standardId.scheme"] = ['BT-157-1', $line->item->standardId?->scheme];
                    }

                    return $codes;
                }),
            self::rule('BR-CL-22', 'The VAT exemption reason code (BT-121) ":value" is not part of the VATEX code list.', CodeList::VATEX,
                static function (InvoiceData $i): array {
                    $codes = [];
                    foreach ($i->vatBreakdown as $index => $breakdown) {
                        $codes["vatBreakdown[{$index}].exemptionReasonCode"] = ['BT-121', $breakdown->exemptionReasonCode];
                    }

                    return $codes;
                }),
            self::rule('BR-CL-23', 'The unit code ":value" is not part of UN/ECE Recommendation 20 or 21.', CodeList::UNITS,
                static function (InvoiceData $i): array {
                    $codes = [];
                    foreach ($i->allLines() as $line) {
                        $codes["line {$line->id}.unit"] = ['BT-130', $line->unit];
                        $codes["line {$line->id}.price.baseQuantityUnit"] = ['BT-150', $line->price->baseQuantityUnit];
                    }

                    return $codes;
                }),
            self::rule('BR-CL-25', 'The electronic address scheme ":value" is not part of the EAS code list.', CodeList::EAS,
                static fn (InvoiceData $i): array => [
                    'seller.electronicAddress.scheme' => ['BT-34-1', $i->seller->electronicAddress?->scheme],
                    'buyer.electronicAddress.scheme' => ['BT-49-1', $i->buyer->electronicAddress?->scheme],
                ]),
            self::rule('BR-CL-26', 'The delivery location identifier scheme ":value" is not part of ISO/IEC 6523.', CodeList::ICD,
                static fn (InvoiceData $i): array => ['delivery.locationId.scheme' => ['BT-71-1', $i->delivery?->locationId?->scheme]]),
        ];
    }

    /**
     * @param  Closure(InvoiceData): array<string, array{0: string, 1: BackedEnum|RawCode|string|null}>  $codes
     */
    private static function rule(string $code, string $fallback, string $list, Closure $codes): Rule
    {
        return CallbackRule::l3($code, $fallback, static function (InvoiceData $i, RuleReporter $report) use ($codes, $list): void {
            foreach ($codes($i) as $path => [$bt, $value]) {
                if ($value === null) {
                    continue;
                }
                $string = match (true) {
                    $value instanceof BackedEnum => (string) $value->value,
                    $value instanceof RawCode => $value->value,
                    default => $value,
                };
                $profile = $report->context()->profile;
                $additional = $profile instanceof ExtendsCodeLists ? $profile->additionalCodes($list) : [];
                if ($string !== '' && ! CodeList::contains($list, $string) && ! in_array($string, $additional, true)) {
                    $report->error($bt, $path, ['value' => $string]);
                }
            }
        });
    }
}
