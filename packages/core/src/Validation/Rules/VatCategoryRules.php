<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use Brick\Math\BigDecimal;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\LineVat;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Validation\Rule;

/**
 * VAT category rules of EN 16931 (BR-S, BR-Z, BR-E, BR-AE, BR-IC, BR-G, BR-O, BR-AF, BR-AG, BR-B).
 *
 * The rules are generated per category because they follow the same pattern:
 *  -01 breakdown present, -02 party identifiers, -05 rate, -08 taxable amount,
 *  -09 tax amount, -10 exemption reason. The Schematron splits -02 and -05 by lines,
 *  allowances and charges (-03, -04, -06, -07); here -02 and -05 cover all three.
 *  "L" (IGIC, BR-AF) and "M" (IPSI, BR-AG) follow "S": several breakdowns (one per rate)
 *  and VAT = taxable amount × rate, but the rate may also be 0. Category specific rules,
 *  e.g. split payment "B" (BR-B-01, BR-B-02), are added below.
 */
final class VatCategoryRules
{
    /** @var array<string, string> */
    private const array PREFIX = [
        'S' => 'BR-S',
        'Z' => 'BR-Z',
        'E' => 'BR-E',
        'AE' => 'BR-AE',
        'K' => 'BR-IC',
        'G' => 'BR-G',
        'O' => 'BR-O',
        'L' => 'BR-AF',
        'M' => 'BR-AG',
    ];

    /** Categories with VAT and several VAT breakdowns, one per rate. */
    private const array PER_RATE = [VatCategory::StandardRate, VatCategory::CanaryIslands, VatCategory::CeutaMelilla];

    /**
     * @return list<Rule>
     */
    public static function all(): array
    {
        $rules = [];
        foreach (self::PREFIX as $value => $prefix) {
            $category = VatCategory::from($value);
            $rules[] = self::breakdownPresent($category, $prefix);
            $rules[] = self::parties($category, $prefix);
            $rules[] = self::rate($category, $prefix);
            $rules[] = self::taxableAmount($category, $prefix);
            $rules[] = self::taxAmount($category, $prefix);
            $rules[] = self::exemptionReason($category, $prefix);
        }

        return [...$rules, ...self::specific()];
    }

    private static function breakdownPresent(VatCategory $category, string $prefix): Rule
    {
        // Only "S", "L" and "M" may have several breakdowns (one per rate); BR-Z-01, BR-E-01,
        // BR-AE-01, BR-IC-01, BR-G-01 and BR-O-01 require exactly one.
        $single = ! in_array($category, self::PER_RATE, true);

        return CallbackRule::l3(
            $prefix.'-01',
            $single
                ? 'An invoice that contains VAT category ":category" shall contain exactly one VAT breakdown (BG-23) with this category.'
                : 'An invoice that contains VAT category ":category" shall contain at least one VAT breakdown (BG-23) with this category.',
            function (InvoiceData $i, RuleReporter $report) use ($category, $single): void {
                if (! $i->isCalculated() || ! Inspect::uses($i, $category)) {
                    return;
                }
                $count = count(array_filter($i->vatBreakdown, static fn ($b): bool => $b->category === $category));
                if ($count === 0 || ($single && $count > 1)) {
                    $report->error('BG-23', 'vatBreakdown', ['category' => $category->value]);
                }
            },
        );
    }

    private static function parties(VatCategory $category, string $prefix): Rule
    {
        /** @var array<string, string> $texts */
        $texts = [
            'S' => 'An invoice with VAT category "S" shall contain the seller VAT identifier (BT-31), the seller tax registration identifier (BT-32) or the seller tax representative VAT identifier (BT-63).',
            'Z' => 'An invoice with VAT category "Z" shall contain the seller VAT identifier (BT-31), the seller tax registration identifier (BT-32) or the seller tax representative VAT identifier (BT-63).',
            'E' => 'An invoice with VAT category "E" shall contain the seller VAT identifier (BT-31), the seller tax registration identifier (BT-32) or the seller tax representative VAT identifier (BT-63).',
            'AE' => 'An invoice with VAT category "AE" shall contain the seller VAT identifier (BT-31), the seller tax registration identifier (BT-32) or the seller tax representative VAT identifier (BT-63), and the buyer VAT identifier (BT-48) or the buyer legal registration identifier (BT-47).',
            'K' => 'An invoice with VAT category "K" (intra-community supply) shall contain the seller VAT identifier (BT-31) or the seller tax representative VAT identifier (BT-63), and the buyer VAT identifier (BT-48).',
            'G' => 'An invoice with VAT category "G" (export) shall contain the seller VAT identifier (BT-31) or the seller tax representative VAT identifier (BT-63).',
            'O' => 'An invoice with VAT category "O" (not subject to VAT) shall not contain the seller VAT identifier (BT-31), the seller tax representative VAT identifier (BT-63) or the buyer VAT identifier (BT-48).',
            'L' => 'An invoice with VAT category "L" (IGIC, Canary Islands) shall contain the seller VAT identifier (BT-31), the seller tax registration identifier (BT-32) or the seller tax representative VAT identifier (BT-63).',
            'M' => 'An invoice with VAT category "M" (IPSI, Ceuta and Melilla) shall contain the seller VAT identifier (BT-31), the seller tax registration identifier (BT-32) or the seller tax representative VAT identifier (BT-63).',
        ];

        return CallbackRule::l3($prefix.'-02', $texts[$category->value] ?? '', function (InvoiceData $i, RuleReporter $report) use ($category): void {
            if (! Inspect::uses($i, $category)) {
                return;
            }

            $sellerVat = Inspect::filled($i->seller->vatId);
            $sellerTax = Inspect::filled($i->seller->taxNumber);
            $representative = Inspect::filled($i->sellerTaxRepresentative?->vatId);
            $buyerVat = Inspect::filled($i->buyer->vatId);
            $buyerLegal = $i->buyer->legalRegistration !== null;

            $ok = match ($category) {
                VatCategory::StandardRate, VatCategory::ZeroRated, VatCategory::Exempt,
                VatCategory::CanaryIslands, VatCategory::CeutaMelilla => $sellerVat || $sellerTax || $representative,
                VatCategory::ReverseCharge => ($sellerVat || $sellerTax || $representative) && ($buyerVat || $buyerLegal),
                VatCategory::IntraCommunitySupply => ($sellerVat || $representative) && $buyerVat,
                VatCategory::Export => $sellerVat || $representative,
                VatCategory::NotSubjectToVat => ! $sellerVat && ! $representative && ! $buyerVat,
                default => true,
            };

            if (! $ok) {
                $report->error($category === VatCategory::NotSubjectToVat ? 'BT-31' : 'BT-31', 'seller.vatId');
            }
        });
    }

    private static function rate(VatCategory $category, string $prefix): Rule
    {
        $text = match ($category) {
            VatCategory::StandardRate => 'In an invoice line, document level allowance or charge with VAT category "S" the VAT rate shall be greater than zero.',
            VatCategory::NotSubjectToVat => 'An invoice line, document level allowance or charge with VAT category "O" shall not contain a VAT rate.',
            VatCategory::CanaryIslands, VatCategory::CeutaMelilla => 'In an invoice line, document level allowance or charge with VAT category ":category" the VAT rate shall be 0 (zero) or greater than zero.',
            default => 'In an invoice line, document level allowance or charge with VAT category ":category" the VAT rate shall be 0 (zero).',
        };

        return CallbackRule::l3($prefix.'-05', $text, function (InvoiceData $i, RuleReporter $report) use ($category): void {
            $entries = [];
            foreach ($i->allLines() as $line) {
                $entries["line {$line->id}.vat.rate"] = [$line->vat->category, $line->vat->rate, 'BT-152'];
            }
            foreach ($i->allowances as $index => $allowance) {
                $entries["allowances[{$index}].vatRate"] = [$allowance->vatCategory, $allowance->vatRate, 'BT-96'];
            }
            foreach ($i->charges as $index => $charge) {
                $entries["charges[{$index}].vatRate"] = [$charge->vatCategory, $charge->vatRate, 'BT-103'];
            }

            foreach ($entries as $path => [$entryCategory, $rate, $bt]) {
                if ($entryCategory !== $category) {
                    continue;
                }
                $ok = match ($category) {
                    VatCategory::StandardRate => $rate !== null && $rate->isPositive(),
                    VatCategory::NotSubjectToVat => $rate === null,
                    // BR-AF-05 … BR-AG-07: the EN 16931 text and the UBL Schematron accept 0; the
                    // CII Schematron requires more than 0 for "L" (QUITTIO-IGIC-ZERO-RATE-CII).
                    VatCategory::CanaryIslands, VatCategory::CeutaMelilla => $rate !== null && ! $rate->isNegative(),
                    default => $rate !== null && $rate->isZero(),
                };
                if (! $ok) {
                    $report->error($bt, $path, ['category' => $category->value]);
                }
            }
        });
    }

    private static function taxableAmount(VatCategory $category, string $prefix): Rule
    {
        return CallbackRule::l3(
            $prefix.'-08',
            'For each VAT rate of category ":category" the taxable amount (BT-116) shall equal the sum of line net amounts minus allowances plus charges of this category and rate.',
            function (InvoiceData $i, RuleReporter $report) use ($category): void {
                if (! $i->isCalculated() || Inspect::withoutLinesByProfile($i, $report)) {
                    return;
                }

                /** @var array<string, BigDecimal> $expected */
                $expected = [];
                $add = static function (string $key, BigDecimal $value) use (&$expected): void {
                    $expected[$key] = ($expected[$key] ?? BigDecimal::zero())->plus($value);
                };
                foreach ($i->lines as $line) {
                    if ($line->vat->category === $category) {
                        $add($line->vat->groupKey(), $line->netAmount?->getAmount() ?? BigDecimal::zero());
                    }
                }
                foreach ($i->allowances as $allowance) {
                    if ($allowance->vatCategory === $category) {
                        $add(LineVat::key($category, $allowance->vatRate), ($allowance->amount?->getAmount() ?? BigDecimal::zero())->negated());
                    }
                }
                foreach ($i->charges as $charge) {
                    if ($charge->vatCategory === $category) {
                        $add(LineVat::key($category, $charge->vatRate), $charge->amount?->getAmount() ?? BigDecimal::zero());
                    }
                }

                foreach ($i->vatBreakdown as $index => $breakdown) {
                    if ($breakdown->category !== $category) {
                        continue;
                    }
                    $key = LineVat::key($category, $category === VatCategory::NotSubjectToVat ? null : $breakdown->rate);
                    $value = $expected[$key] ?? BigDecimal::zero();
                    if (! $breakdown->taxableAmount->getAmount()->isEqualTo($value)) {
                        $report->error('BT-116', "vatBreakdown[{$index}].taxableAmount", ['category' => $category->value]);
                    }
                }
            },
        );
    }

    private static function taxAmount(VatCategory $category, string $prefix): Rule
    {
        $withVat = in_array($category, self::PER_RATE, true);
        $text = match (true) {
            $category === VatCategory::StandardRate => 'The VAT category tax amount (BT-117) for category "S" shall equal the taxable amount (BT-116) multiplied by the rate (BT-119).',
            $withVat => 'The VAT category tax amount (BT-117) for category ":category" shall equal the taxable amount (BT-116) multiplied by the rate (BT-119).',
            default => 'The VAT category tax amount (BT-117) in a VAT breakdown with category ":category" shall be 0 (zero).',
        };

        return CallbackRule::l3($prefix.'-09', $text, function (InvoiceData $i, RuleReporter $report) use ($category, $withVat): void {
            foreach ($i->vatBreakdown as $index => $breakdown) {
                if ($breakdown->category !== $category) {
                    continue;
                }
                if ($withVat) {
                    $rate = $breakdown->rate ?? BigDecimal::zero();
                    $expected = $breakdown->taxableAmount->getAmount()->multipliedBy($rate)->dividedBy(100, 2, \Brick\Math\RoundingMode::HalfUp);
                    // Same tolerance as the official Schematron (one currency unit).
                    $ok = $breakdown->taxAmount->getAmount()->minus($expected)->abs()->isLessThanOrEqualTo(1);
                } else {
                    $ok = $breakdown->taxAmount->isZero();
                }
                if (! $ok) {
                    $report->error('BT-117', "vatBreakdown[{$index}].taxAmount", ['category' => $category->value]);
                }
            }
        });
    }

    private static function exemptionReason(VatCategory $category, string $prefix): Rule
    {
        $required = $category->requiresExemptionReason();
        $text = $required
            ? 'A VAT breakdown with category ":category" shall have a VAT exemption reason code (BT-121) or a VAT exemption reason text (BT-120).'
            : 'A VAT breakdown with category ":category" shall not have a VAT exemption reason code (BT-121) or text (BT-120).';

        return CallbackRule::l3($prefix.'-10', $text, function (InvoiceData $i, RuleReporter $report) use ($category, $required): void {
            foreach ($i->vatBreakdown as $index => $breakdown) {
                if ($breakdown->category !== $category) {
                    continue;
                }
                $has = Inspect::filled($breakdown->exemptionReason) || $breakdown->exemptionReasonCode !== null;
                if ($has !== $required) {
                    $report->error('BT-120', "vatBreakdown[{$index}].exemptionReason", ['category' => $category->value]);
                }
            }
        });
    }

    /**
     * @return list<Rule>
     */
    private static function specific(): array
    {
        return [
            CallbackRule::l3('BR-IC-11', 'In an invoice with VAT category "K" (intra-community supply) the actual delivery date (BT-72) or the invoicing period (BG-14) shall not be blank.', function (InvoiceData $i, RuleReporter $report): void {
                if (Inspect::uses($i, VatCategory::IntraCommunitySupply)
                    && $i->delivery?->actualDeliveryDate === null && $i->invoicingPeriod === null) {
                    $report->error('BT-72', 'delivery.actualDeliveryDate');
                }
            }),
            CallbackRule::l3('BR-IC-12', 'In an invoice with VAT category "K" (intra-community supply) the deliver to country code (BT-80) shall not be blank.', function (InvoiceData $i, RuleReporter $report): void {
                if (Inspect::uses($i, VatCategory::IntraCommunitySupply) && ! Inspect::filled($i->delivery?->address?->countryCode)) {
                    $report->error('BT-80', 'delivery.address.countryCode');
                }
            }),
            CallbackRule::l3('BR-O-11', 'An invoice with VAT category "O" (not subject to VAT) shall not contain other VAT categories.', function (InvoiceData $i, RuleReporter $report): void {
                $categories = Inspect::categories($i);
                if (in_array(VatCategory::NotSubjectToVat, $categories, true) && count($categories) > 1) {
                    $report->error('BG-23', 'vatBreakdown');
                }
            }),
            // The Schematron requires every country code of the document to be "IT" (UBL:
            // cbc:IdentificationCode, which includes the item country of origin; CII: ram:CountryID).
            CallbackRule::l3('BR-B-01', 'An invoice with VAT category "B" (split payment) shall be a domestic Italian invoice: every country code (BT-40, BT-55, BT-69, BT-80, BT-159) shall be "IT", found ":value".', function (InvoiceData $i, RuleReporter $report): void {
                if (! in_array(VatCategory::TransferredVatItaly, self::categoriesWithBreakdown($i), true)) {
                    return;
                }
                foreach (self::countryCodes($i) as $path => [$code, $bt]) {
                    if (strtoupper(trim($code)) !== 'IT') {
                        $report->error($bt, $path, ['value' => $code]);

                        return;
                    }
                }
            }),
            CallbackRule::l3('BR-B-02', 'An invoice with VAT category "B" (split payment) shall not contain lines, document level allowances, charges or VAT breakdowns with VAT category "S".', function (InvoiceData $i, RuleReporter $report): void {
                $categories = self::categoriesWithBreakdown($i);
                if (in_array(VatCategory::TransferredVatItaly, $categories, true) && in_array(VatCategory::StandardRate, $categories, true)) {
                    $report->error('BT-151', 'lines');
                }
            }),
            CallbackRule::l3('QUITTIO-IGIC-ZERO-RATE-CII', 'VAT category "L" (IGIC) with the rate 0 in :value: EN 16931 and UBL accept it (BR-AF-05 to BR-AF-07), the official CII Schematron requires a rate greater than zero. Use UBL for this invoice.', function (InvoiceData $i, RuleReporter $report): void {
                $syntax = $report->context()->option('syntax');
                if ($syntax === Syntax::Ubl || ($syntax === null && ! in_array(Syntax::Cii, $report->context()->profile->syntaxes(), true))) {
                    return;
                }
                $entries = [];
                foreach ($i->allLines() as $line) {
                    $entries["line {$line->id}.vat.rate"] = [$line->vat->category, $line->vat->rate, 'BT-152'];
                }
                foreach ($i->allowances as $index => $allowance) {
                    $entries["allowances[{$index}].vatRate"] = [$allowance->vatCategory, $allowance->vatRate, 'BT-96'];
                }
                foreach ($i->charges as $index => $charge) {
                    $entries["charges[{$index}].vatRate"] = [$charge->vatCategory, $charge->vatRate, 'BT-103'];
                }
                foreach ($entries as $path => [$category, $rate, $bt]) {
                    if ($category === VatCategory::CanaryIslands && $rate !== null && $rate->isZero()) {
                        $report->warning($bt, $path, ['value' => $path]);
                    }
                }
            }),
        ];
    }

    /**
     * Categories of lines, document level allowances and charges, and of the VAT breakdown.
     *
     * @return list<VatCategory>
     */
    private static function categoriesWithBreakdown(InvoiceData $invoice): array
    {
        $categories = [];
        foreach (Inspect::categories($invoice) as $category) {
            $categories[$category->value] = $category;
        }
        foreach ($invoice->vatBreakdown as $breakdown) {
            $categories[$breakdown->category->value] = $breakdown->category;
        }

        return array_values($categories);
    }

    /**
     * Every country code the XML carries, by path: code and business term.
     *
     * @return array<string, array{string, ?string}>
     */
    private static function countryCodes(InvoiceData $invoice): array
    {
        $codes = [
            'seller.address.countryCode' => [$invoice->seller->address->countryCode, 'BT-40'],
            'buyer.address.countryCode' => [$invoice->buyer->address->countryCode, 'BT-55'],
        ];
        if ($invoice->sellerTaxRepresentative !== null) {
            $codes['sellerTaxRepresentative.address.countryCode'] = [$invoice->sellerTaxRepresentative->address->countryCode, 'BT-69'];
        }
        if ($invoice->delivery?->address !== null) {
            $codes['delivery.address.countryCode'] = [$invoice->delivery->address->countryCode, 'BT-80'];
        }
        $extended = $invoice->extended;
        foreach (['invoicer' => $extended?->invoicer, 'invoicee' => $extended?->invoicee, 'ultimateCustomer' => $extended?->ultimateCustomer, 'shipFrom' => $extended?->shipFrom] as $party => $entry) {
            $address = $entry?->address;
            if ($address !== null) {
                $codes["extended.{$party}.address.countryCode"] = [$address->countryCode, null];
            }
        }
        foreach ($invoice->allLines() as $line) {
            if (Inspect::filled($line->item->originCountry)) {
                $codes["line {$line->id}.item.originCountry"] = [(string) $line->item->originCountry, 'BT-159'];
            }
        }

        return $codes;
    }
}
