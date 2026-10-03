<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Rules;

use Closure;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Validation\Rule;
use Quittio\Core\Validation\ValidationLevel;

/**
 * Structure and data format checks of the data model (level L1).
 */
final class StructureRules
{
    /**
     * @return list<Rule>
     */
    public static function all(): array
    {
        $r = static fn (string $code, string $fallback, Closure $check): Rule => new CallbackRule($code, ValidationLevel::L1, $fallback, $check);

        return [
            $r('QUITTIO-NOT-CALCULATED', 'The invoice has not been calculated. Totals (BG-22) and the VAT breakdown (BG-23) are missing.', function (InvoiceData $i, RuleReporter $report): void {
                if (! $i->isCalculated()) {
                    $report->error('BG-22', 'totals');
                }
            }),
            $r('QUITTIO-FORMAT-COUNTRY', 'The country code ":value" must consist of two upper case letters.', function (InvoiceData $i, RuleReporter $report): void {
                foreach ([
                    'seller.address.countryCode' => ['BT-40', $i->seller->address->countryCode],
                    'buyer.address.countryCode' => ['BT-55', $i->buyer->address->countryCode],
                ] as $path => [$bt, $code]) {
                    if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
                        $report->error($bt, $path, ['value' => $code]);
                    }
                }
            }),
            $r('QUITTIO-FORMAT-CURRENCY', 'The currency code ":value" must consist of three upper case letters.', function (InvoiceData $i, RuleReporter $report): void {
                foreach (['currency' => ['BT-5', $i->currency], 'taxCurrency' => ['BT-6', $i->taxCurrency]] as $path => [$bt, $code]) {
                    if ($code !== null && preg_match('/^[A-Z]{3}$/', $code) !== 1) {
                        $report->error($bt, $path, ['value' => $code]);
                    }
                }
            }),
            $r('QUITTIO-PRECISION', 'Line :line uses :value decimals for quantity or price. Many receiving systems support at most :max.', function (InvoiceData $i, RuleReporter $report): void {
                $max = $report->context()->intOption('max_decimals', 4);
                foreach ($i->allLines() as $line) {
                    $scale = max(
                        $line->quantity->strippedOfTrailingZeros()->getScale(),
                        $line->price->netPrice?->strippedOfTrailingZeros()->getScale() ?? 0,
                    );
                    if ($scale > $max) {
                        $report->warning('BT-146', "line {$line->id}.price.netPrice", ['line' => $line->id, 'value' => $scale, 'max' => $max]);
                    }
                }
            }),
        ];
    }
}
