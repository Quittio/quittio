<?php

declare(strict_types=1);

namespace Quittio\Core\Corrections;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\DeclaredAmountsState;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\TotalsCalculator;
use Quittio\Core\Countries\CorrectionPolicy;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Lines\Item;
use Quittio\Core\Data\Lines\LineVat;
use Quittio\Core\Data\Lines\Price;
use Quittio\Core\Data\Meta;
use Quittio\Core\Data\Note;
use Quittio\Core\Data\Payment\PaymentTerms;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Data\References\PrecedingInvoiceReference;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;
use Quittio\Core\View\IntlFormatter;

/**
 * Document on a reduction of the consideration of an issued invoice (section 17 UStG), e.g. a
 * cash discount taken, a reduction because of a complaint about defects or a bonus: a credit
 * note (type code 381, positive amounts) with one line per VAT group of the original, the
 * reference to the original (BG-3) and a note that names the reduction of the net amount and
 * of the VAT per rate (BT-22), titled "Entgeltminderung" in Germany.
 *
 * It is no cancellation and no correction of the invoice: the invoice stays correct, only the
 * consideration changed (section 14.11 (4) UStAE). In Germany a document is only required for
 * section 17 (4) UStG, and it need not be an e-invoice (section 17.1 (3a) UStAE); a cash
 * discount may as well be booked on receipt of the payment.
 *
 * The reduction is a percentage of every VAT group, the net amount of an invoice with one VAT
 * group, or net amounts per VAT group keyed "category:rate", e.g. ['S:19' => '50.00', 'S:7' => '5.00'].
 */
final class PriceReduction
{
    /**
     * @param  array<string, BigDecimal|int|string>|null  $amounts  net amount per VAT group, keyed "category:rate"
     *         ("S:19", "E:0", "O" for a group without rate)
     * @param  string|null  $title  document title in the PDF; without it the title "price_reduction" of
     *         $policy, otherwise the label "price_reduction_title"
     *
     * @throws InvalidArgumentException for a credit note, a self-billed document or an invoice
     *         without calculated VAT breakdown, or for amounts that do not fit the original
     */
    public static function of(
        InvoiceData $original,
        string $number,
        PriceReductionReason $reason,
        BigDecimal|int|string|null $percent = null,
        BigDecimal|int|string|null $amount = null,
        ?array $amounts = null,
        ?Date $issueDate = null,
        ?string $locale = null,
        ?string $title = null,
        ?CorrectionPolicy $policy = null,
        TotalsCalculator $calculator = new En16931Calculator,
    ): InvoiceBuilder {
        if ($original->isCreditNote() || ($original->typeCode instanceof InvoiceTypeCode && $original->typeCode->isSelfBilled())) {
            throw new InvalidArgumentException(sprintf('The consideration of the document %s (type code %s) cannot be reduced by a price reduction document.', $original->number, $original->typeCodeValue()));
        }
        if ($original->vatBreakdown === []) {
            throw new InvalidArgumentException(sprintf('The invoice %s must be calculated before its consideration is reduced.', $original->number));
        }
        if (count(array_filter([$percent, $amount, $amounts], static fn ($value): bool => $value !== null)) !== 1) {
            throw new InvalidArgumentException('Give the reduction as exactly one of percent, amount or amounts.');
        }

        $locale ??= $original->meta->locale ?? 'en';
        $translator = Translations::translator();
        $formatter = new IntlFormatter;
        $reductions = self::reductions($original, $percent === null ? null : Decimal::of($percent), $amount === null ? null : Decimal::of($amount), $amounts);

        $lines = [];
        $texts = [];
        foreach ($reductions as $index => [$row, $net]) {
            $lines[] = new InvoiceLine(
                id: (string) ($index + 1),
                quantity: BigDecimal::one(),
                unit: UnitCode::Unit,
                price: new Price(netPrice: $net->getAmount()),
                vat: self::lineVat($original, $row),
                item: new Item(name: $translator->get('quittio::labels.price_reduction_line', [], $locale)),
            );
            $rate = $row->rate;
            $texts[] = $rate !== null && $rate->isPositive()
                ? $translator->get('quittio::labels.price_reduction_taxed', [
                    'net' => $formatter->money($net, $locale),
                    'rate' => $formatter->percent($rate, $locale),
                    'vat' => $formatter->money(Amount::of($net->getAmount()->multipliedBy($rate)->dividedBy(100, Amount::SCALE, RoundingMode::HalfUp), $original->currency), $locale),
                ], $locale)
                : $translator->get('quittio::labels.price_reduction_untaxed', ['net' => $formatter->money($net, $locale)], $locale);
        }

        $because = $translator->get('quittio::labels.price_reduction_reason_'.$reason->value, [], $locale)
            .($percent === null ? '' : ' ('.$formatter->percent(Decimal::of($percent), $locale).')');
        $note = $translator->get('quittio::labels.price_reduction_note', [
            'number' => $original->number,
            'date' => $formatter->date($original->issueDate, $locale),
            'reason' => $because,
        ], $locale).' '.implode(' ', $texts);

        // Regulatory and tax notes of the original (e.g. retention or exemption hints) still apply.
        $notes = [new Note($note, NoteSubjectCode::GeneralInformation)];
        foreach ($original->notes as $existing) {
            if (in_array($existing->subjectCode, [NoteSubjectCode::RegulatoryInformation, NoteSubjectCode::TaxDeclaration], true)) {
                $notes[] = $existing;
            }
        }

        $title ??= $policy?->title('price_reduction', $locale) ?? $translator->get('quittio::labels.price_reduction_title', [], $locale);

        $reduction = $original->with([
            'number' => $number,
            'issueDate' => $issueDate ?? Date::today($original->meta->timezone),
            'typeCode' => InvoiceTypeCode::CreditNote,
            'precedingInvoices' => [new PrecedingInvoiceReference($original->number, $original->issueDate)],
            'notes' => $notes,
            'dueDate' => null,
            'paymentTerms' => new PaymentTerms(text: $translator->get($reason === PriceReductionReason::CashDiscount ? 'quittio::labels.price_reduction_terms_settled' : 'quittio::labels.cancellation_terms', [], $locale)),
            'lines' => $lines,
            'allowances' => [],
            'charges' => [],
            'prepaidAmount' => null,
            'roundingAmount' => null,
            'xrechnungExtension' => $original->xrechnungExtension?->with(['thirdPartyPayments' => []]),
            'totals' => null,
            'vatBreakdown' => [],
            'presentation' => ($original->presentation ?? new Presentation)->with(['title' => $title]),
            'meta' => DeclaredAmountsState::none()->applyTo($original->meta)->withFlag(Meta::FLAG_PRICE_REDUCTION),
        ]);

        return InvoiceBuilder::fromUsing($reduction, $calculator);
    }

    /**
     * The VAT groups of the original with the net amount of their reduction, without groups
     * that are not reduced.
     *
     * @param  array<string, BigDecimal|int|string>|null  $amounts
     * @return list<array{VatBreakdown, Money}>
     */
    private static function reductions(InvoiceData $original, ?BigDecimal $percent, ?BigDecimal $amount, ?array $amounts): array
    {
        $groups = $original->vatBreakdown;
        if ($amount !== null && count($groups) !== 1) {
            throw new InvalidArgumentException(sprintf('The invoice %s has %d VAT groups: give the reduction as percent or as amounts per VAT group ("category:rate").', $original->number, count($groups)));
        }
        if ($percent !== null && (! $percent->isPositive() || $percent->isGreaterThan(100))) {
            throw new InvalidArgumentException('The percentage must be greater than 0 and at most 100.');
        }

        $byKey = [];
        foreach ($amounts ?? [] as $key => $value) {
            $byKey[self::normaliseKey((string) $key)] = Decimal::of($value);
        }

        $reductions = [];
        foreach ($groups as $row) {
            $key = self::groupKey($row);
            $net = match (true) {
                $percent !== null => $row->taxableAmount->getAmount()->multipliedBy($percent)->dividedBy(100, Amount::SCALE, RoundingMode::HalfUp),
                $amount !== null => $amount,
                default => $byKey[$key] ?? null,
            };
            unset($byKey[$key]);
            if ($net === null || $net->isZero()) {
                continue;
            }
            if ($net->isNegative() || $net->isGreaterThan($row->taxableAmount->getAmount())) {
                throw new InvalidArgumentException(sprintf('The reduction of the VAT group %s must be greater than 0 and at most %s.', $key, Amount::format($row->taxableAmount)));
            }
            $reductions[] = [$row, Amount::of($net->toScale(Amount::SCALE, RoundingMode::HalfUp), $original->currency)];
        }
        if ($byKey !== []) {
            throw new InvalidArgumentException(sprintf('The invoice %s has no VAT group %s.', $original->number, implode(', ', array_keys($byKey))));
        }
        if ($reductions === []) {
            throw new InvalidArgumentException('The reduction is zero.');
        }

        return $reductions;
    }

    /**
     * The VAT of the reduction line: that of the first line of the group, with its preset and
     * exemption reason, otherwise the group's.
     */
    private static function lineVat(InvoiceData $original, VatBreakdown $row): LineVat
    {
        foreach ($original->allLines() as $line) {
            if ($line->vat->groupKey() === LineVat::key($row->category, $row->rate)) {
                return $line->vat;
            }
        }

        return new LineVat($row->category, $row->rate, $row->exemptionReason, $row->exemptionReasonCode);
    }

    private static function groupKey(VatBreakdown $row): string
    {
        return $row->rate === null ? $row->category->value : $row->category->value.':'.$row->rate->strippedOfTrailingZeros()->toString();
    }

    private static function normaliseKey(string $key): string
    {
        $parts = explode(':', strtoupper(trim($key)), 2);

        return count($parts) === 1 ? $parts[0] : $parts[0].':'.Decimal::of($parts[1])->strippedOfTrailingZeros()->toString();
    }
}
