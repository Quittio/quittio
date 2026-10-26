<?php

declare(strict_types=1);

namespace Quittio\Core\Corrections;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Closure;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\DeclaredAmounts;
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
 * consideration changed (section 14.11 (4) UStAE). A prepayment invoice (386) cannot be reduced:
 * its advance payment is settled in the final invoice, an advance refunded is cancelled. In Germany a document is only required for
 * section 17 (4) UStG, and it need not be an e-invoice (section 17.1 (3a) UStAE); a cash
 * discount may as well be booked on receipt of the payment.
 *
 * The reduction is a percentage of every VAT group, the net amount of an invoice with one VAT
 * group, or net amounts per VAT group keyed "category:rate", e.g. ['S:19' => '50.00', 'S:7' => '5.00'].
 *
 * VAT is rounded per document and VAT group (BR-CO-17): without history, the VAT of a reduction
 * is round2(its net amount × rate). With a CancellationHistory of the documents that credited the
 * original before (its partial cancellations, its price reductions and the cancellations of
 * those), the VAT is rounded cumulatively per VAT group, as for partial cancellations: the VAT
 * credited so far is round2(net amount credited so far × rate), and exactly the original's VAT
 * (BT-117) once the whole net amount of the group is credited (the group then closes, see
 * CancellationHistory). Three reductions of 0.50 of 1.50 net at 19 % (VAT 0.29) then carry 0.10,
 * 0.09 and 0.10 instead of 3 × 0.10. A VAT amount that differs from the reduction's own rounding
 * is declared (Meta::FLAG_CANCELLATION_VAT_ALIGNED, QUITTIO-CANCELLATION-VAT-ALIGNED, at most
 * 0.01 and with the sign of the net amount), BT-111 in accounting currency is aligned the same
 * way, and the note states the aligned VAT per rate followed by the rounding adjustment sentence
 * (label "price_reduction_vat_aligned"). With a history, the reductions and cancellations
 * together cannot credit more than the taxable amount of a group.
 */
final class PriceReduction
{
    /**
     * @param  array<string, BigDecimal|int|string>|null  $amounts  net amount per VAT group, keyed "category:rate"
     *         ("S:19", "E:0", "O" for a group without rate)
     * @param  string|null  $title  document title in the PDF; without it the title "price_reduction" of
     *         $policy, otherwise the label "price_reduction_title"
     * @param  TotalsCalculator  $calculator  calculator of the VAT alignment with $history, e.g. the one of the Quittio instance
     * @param  CancellationHistory|null  $history  what the earlier partial cancellations and price reductions of the original
     *         (less what their cancellations reversed) credited per VAT group, for the cumulative rounding of the VAT
     *         (see the class description and CancellationHistory); without it the VAT is rounded per document
     *
     * @throws InvalidArgumentException for a credit note, a self-billed document, a prepayment invoice
     *         (386, also a document with Meta::FLAG_PREPAYMENT_CORRECTION) or an invoice without
     *         calculated VAT breakdown, for amounts that do not fit the original, or for a history
     *         that does not fit it
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
        ?CancellationHistory $history = null,
    ): InvoiceBuilder {
        if ($original->isCreditNote() || ($original->typeCode instanceof InvoiceTypeCode && $original->typeCode->isSelfBilled())) {
            throw new InvalidArgumentException(sprintf('The consideration of the document %s (type code %s) cannot be reduced by a price reduction document.', $original->number, $original->typeCodeValue()));
        }
        // A prepayment invoice invoices an advance payment, not a supply: the VAT is owed on the
        // amount received (section 13 (1) no. 1 lit. a sentence 4 UStG), and the final invoice
        // deducts only that amount (section 14 (5) sentence 2 UStG). A reduction of the price is
        // settled there; an advance refunded is a (partial) cancellation (section 17 (2) no. 2 UStG).
        if ($original->typeCode === InvoiceTypeCode::PrepaymentInvoice || $original->meta->hasFlag(Meta::FLAG_PREPAYMENT_CORRECTION)) {
            throw new InvalidArgumentException(sprintf('The consideration of the prepayment invoice %s cannot be reduced by a price reduction document: reduce the price in the final invoice, which deducts the advance payment received, or cancel the prepayment invoice fully or partly for an advance payment refunded.', $original->number));
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
        $reductions = self::reductions($original, $percent === null ? null : Decimal::of($percent), $amount === null ? null : Decimal::of($amount), $amounts, $history);

        $lines = [];
        foreach ($reductions as $index => [$row, $net]) {
            $lines[] = new InvoiceLine(
                id: (string) ($index + 1),
                quantity: BigDecimal::one(),
                unit: UnitCode::Unit,
                price: new Price(netPrice: $net->getAmount()),
                vat: self::lineVat($original, $row),
                item: new Item(name: $translator->get('quittio::labels.price_reduction_line', [], $locale)),
            );
        }

        $because = $translator->get('quittio::labels.price_reduction_reason_'.$reason->value, [], $locale)
            .($percent === null ? '' : ' ('.$formatter->percent(Decimal::of($percent), $locale).')');
        $intro = $translator->get('quittio::labels.price_reduction_note', [
            'number' => $original->number,
            'date' => $formatter->date($original->issueDate, $locale),
            'reason' => $because,
        ], $locale);

        // Regulatory and tax notes of the original (e.g. retention or exemption hints) still apply.
        $notes = [new Note(self::note($original, $reductions, $intro, $locale), NoteSubjectCode::GeneralInformation)];
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

        if ($history !== null) {
            $reduction = self::alignVat($original, $reduction, $history, $calculator, static fn (array $vat): string => self::note($original, $reductions, $intro, $locale, $vat, aligned: true));
        }

        return InvoiceBuilder::fromUsing($reduction, $calculator);
    }

    /**
     * The price reduction with its VAT per group (BT-117) and BT-111 aligned with $history and
     * declared, so that the calculator keeps them (within the tolerance of BR-CO-17, BT-111 within
     * 0.01); unchanged if the aligned amounts are its own. A group in which the reduction brings
     * the net amount credited so far to exactly the original's taxable amount closes (it then
     * carries the rest of the original's VAT), in addition to the groups $history closes.
     *
     * @param  Closure(array<string, Money>): string  $note  the note with the given (aligned) VAT per group key and the
     *         rounding adjustment sentence
     */
    private static function alignVat(InvoiceData $original, InvoiceData $reduction, CancellationHistory $history, TotalsCalculator $calculator, Closure $note): InvoiceData
    {
        $calculated = $calculator->calculate(InvoiceBuilder::fromUsing($reduction, $calculator)->build());
        $history = self::closing($history, $original, $calculated);
        $aligned = $history->align($original, $calculated);
        $history->assertFits($original, $calculated, $aligned['breakdown']);

        $vat = [];
        $deviates = false;
        foreach ($aligned['breakdown'] as $index => $entry) {
            $vat[DeclaredAmounts::breakdownKey($entry->category, $entry->rate)] = $entry->taxAmount;
            $deviates = $deviates || ! $entry->taxAmount->isEqualTo($calculated->vatBreakdown[$index]->taxAmount);
        }
        $inTaxCurrency = $aligned['taxInTaxCurrency'];
        $own = $calculated->totals?->vatTotalInTaxCurrency?->getAmount();
        if (! $deviates && ($inTaxCurrency === null || ($own !== null && $inTaxCurrency->isEqualTo($own)))) {
            return $reduction;
        }

        $state = DeclaredAmountsState::fromMeta($reduction->meta)->withVatAligned();
        $meta = $reduction->meta;
        if ($inTaxCurrency !== null) {
            // At the exchange rate of the original, also when implied by its declared amounts.
            $state = $state->withVatInTaxCurrency($inTaxCurrency);
            $meta = $meta->with(['exchangeRate' => $original->withImpliedExchangeRate()->meta->exchangeRate]);
        }

        $notes = $reduction->notes;
        if ($deviates) {
            $notes[0] = $notes[0]->with(['content' => $note($vat)]);
        }

        return $reduction->with([
            'vatBreakdown' => $aligned['breakdown'],
            'notes' => $notes,
            'meta' => $state->applyTo($meta),
        ]);
    }

    /**
     * The note on the reduction: $intro, then per reduced VAT group the net amount and the VAT,
     * round2(net amount × rate) or the VAT given per group key in $vat (aligned with a history),
     * and with $aligned the rounding adjustment sentence.
     *
     * @param  list<array{VatBreakdown, Money}>  $reductions
     * @param  array<string, Money>  $vat
     */
    private static function note(InvoiceData $original, array $reductions, string $intro, string $locale, array $vat = [], bool $aligned = false): string
    {
        $translator = Translations::translator();
        $formatter = new IntlFormatter;
        $texts = [];
        foreach ($reductions as [$row, $net]) {
            $rate = $row->rate;
            $texts[] = $rate !== null && $rate->isPositive()
                ? $translator->get('quittio::labels.price_reduction_taxed', [
                    'net' => $formatter->money($net, $locale),
                    'rate' => $formatter->percent($rate, $locale),
                    'vat' => $formatter->money($vat[DeclaredAmounts::breakdownKey($row->category, $rate)] ?? Amount::of($net->getAmount()->multipliedBy($rate)->dividedBy(100, Amount::SCALE, RoundingMode::HalfUp), $original->currency), $locale),
                ], $locale)
                : $translator->get('quittio::labels.price_reduction_untaxed', ['net' => $formatter->money($net, $locale)], $locale);
        }
        if ($aligned) {
            $texts[] = $translator->get('quittio::labels.price_reduction_vat_aligned', ['number' => $original->number], $locale);
        }

        return $intro.' '.implode(' ', $texts);
    }

    /**
     * $history with the VAT groups closed in which the calculated reduction brings the net amount
     * credited so far to exactly the original's taxable amount (BT-116).
     */
    private static function closing(CancellationHistory $history, InvoiceData $original, InvoiceData $reduction): CancellationHistory
    {
        $taxable = [];
        foreach ($original->vatBreakdown as $row) {
            $key = DeclaredAmounts::breakdownKey($row->category, $row->rate);
            $taxable[$key] = ($taxable[$key] ?? BigDecimal::zero())->plus($row->taxableAmount->getAmount());
        }

        $closes = $history->closes;
        foreach (CancellationHistory::entries($original, $reduction) as $entry) {
            if (isset($taxable[$entry['key']]) && $history->taxable($entry['key'])->plus($entry['taxable'])->isEqualTo($taxable[$entry['key']])) {
                $closes[] = $entry['key'];
            }
        }

        return new CancellationHistory($history->groups, array_values(array_unique($closes)));
    }

    /**
     * The VAT groups of the original with the net amount of their reduction, without groups
     * that are not reduced. With a history, a group can only be reduced by what the earlier
     * cancellations and price reductions left of its taxable amount.
     *
     * @param  array<string, BigDecimal|int|string>|null  $amounts
     * @return list<array{VatBreakdown, Money}>
     */
    private static function reductions(InvoiceData $original, ?BigDecimal $percent, ?BigDecimal $amount, ?array $amounts, ?CancellationHistory $history): array
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
            $credited = $history?->taxable(DeclaredAmounts::breakdownKey($row->category, $row->rate)) ?? BigDecimal::zero();
            $left = $row->taxableAmount->getAmount()->minus($credited);
            if ($credited->isPositive() && $net->isGreaterThan($left)) {
                throw new InvalidArgumentException(sprintf('The reduction of the VAT group %s must be at most %s: the earlier cancellations and price reductions of invoice %s credit %s of its taxable amount %s.', $key, BigDecimal::max($left, BigDecimal::zero())->toScale(2)->toString(), $original->number, $credited->toScale(2)->toString(), Amount::format($row->taxableAmount)));
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
