<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use Brick\Math\BigDecimal;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\LineVat;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Validation\Rule;

/**
 * VAT category rules of EN 16931 (BR-S, BR-Z, BR-E, BR-AE, BR-IC, BR-G, BR-O).
 *
 * The rules are generated per category because they follow the same pattern:
 *  -01 breakdown present, -02 party identifiers, -05 rate, -08 taxable amount,
 *  -09 tax amount, -10 exemption reason. Category specific rules are added below.
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
    ];

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
        // Only "S" may have several breakdowns (one per rate); BR-Z-01, BR-E-01, BR-AE-01,
        // BR-IC-01, BR-G-01 and BR-O-01 require exactly one.
        $single = $category !== VatCategory::StandardRate;

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
                VatCategory::StandardRate, VatCategory::ZeroRated, VatCategory::Exempt => $sellerVat || $sellerTax || $representative,
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
        $text = $category === VatCategory::StandardRate
            ? 'The VAT category tax amount (BT-117) for category "S" shall equal the taxable amount (BT-116) multiplied by the rate (BT-119).'
            : 'The VAT category tax amount (BT-117) in a VAT breakdown with category ":category" shall be 0 (zero).';

        return CallbackRule::l3($prefix.'-09', $text, function (InvoiceData $i, RuleReporter $report) use ($category): void {
            foreach ($i->vatBreakdown as $index => $breakdown) {
                if ($breakdown->category !== $category) {
                    continue;
                }
                if ($category === VatCategory::StandardRate) {
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
        ];
    }
}
