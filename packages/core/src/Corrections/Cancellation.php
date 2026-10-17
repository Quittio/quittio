<?php

declare(strict_types=1);

namespace Quittio\Core\Corrections;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\DeclaredAmounts;
use Quittio\Core\Calculation\DeclaredAmountsState;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\GrossPriceConverter;
use Quittio\Core\Calculation\LineCalculator;
use Quittio\Core\Calculation\TotalsCalculator;
use Quittio\Core\Countries\CorrectionPolicy;
use Quittio\Core\Data\AllowanceCharge\DocumentAllowanceCharge;
use Quittio\Core\Data\AllowanceCharge\LineAllowanceCharge;
use Quittio\Core\Data\Extensions\ThirdPartyPayment;
use Quittio\Core\Data\Extensions\XRechnungExtensionData;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
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
 * Full cancellation of an issued invoice: a new invoice with its
 * own number that reverses the original and refers to it (BG-3).
 *
 * Sign strategies (17.2):
 * - credit_note:      type code 381, amounts positive; UBL writes a CreditNote document,
 * - negative_invoice: type code 380, quantities and allowance/charge amounts negated.
 *
 * The cancellation of a credit note is an invoice (380) with the same positive amounts in
 * both strategies.
 *
 * Self-billed documents stay self-billed (issued by the buyer, labelled "Gutschrift" by the
 * kept regulatory note): a self-billed invoice (389) or debit note (527) is cancelled by a
 * self-billed credit note (261) or a negative self-billed invoice (389), a self-billed credit
 * note by a self-billed invoice (389) with positive amounts.
 *
 * Both are permitted in Germany; apart from self-billing the document must not be called
 * "Gutschrift". The original data is never changed.
 *
 * Declared amounts (Meta::FLAG_DECLARED_AMOUNTS): a full cancellation reverses the declared
 * line net amounts (BT-131) and the declared VAT breakdown (BT-116, BT-117) of the original,
 * also if another system rounded differently (BT-131 within 0.02, PEPPOL-EN16931-R120; BT-117
 * within the tolerance of BR-CO-17), so that the totals BT-106 to BT-115 are reversed exactly.
 * A partial cancellation keeps the declared amounts of whole cancelled lines; quantities and
 * amounts of a line are recalculated. Its VAT is that of the cancelled part, round2(taxable
 * amount × rate) per VAT group, unless a CancellationHistory of the earlier partial
 * cancellations is given: then the VAT is rounded cumulatively per group and declared
 * (Meta::FLAG_CANCELLATION_VAT_ALIGNED), so that all cancellations together reverse exactly the
 * taxable amount (BT-116) and the VAT (BT-117) of every group and BT-111 in accounting currency.
 * In gross price mode the net amounts follow cumulatively from the gross amounts cancelled so far
 * (round2(G / (1 + rate)) like the original) and the rounding amount (BT-114) from the amounts used,
 * so that the amount due stays the gross amount of the cancelled lines. Every cancellation then
 * differs by at most 0.01 from round2(own taxable amount × rate), with the sign of its taxable
 * amount (QUITTIO-CANCELLATION-VAT-ALIGNED, mentioned in the cancellation note); for an original
 * whose VAT another system rounded, from round2(own taxable amount × the original's BT-117 /
 * BT-116), which spreads that rounding over the parts. A history that does not fit the original
 * (CancellationHistory::assertFits()) is rejected.
 */
final class Cancellation
{
    public const array STRATEGIES = [CorrectionPolicy::CANCELLATION_CREDIT_NOTE, CorrectionPolicy::CANCELLATION_NEGATIVE_INVOICE];

    /**
     * @param  string|null  $title  document title in the PDF, e.g. "Stornorechnung"; without it the
     *         title comes from $policy ("cancellation_self_billed" for self-billed originals)
     * @param  list<array{line: string, quantity?: BigDecimal|int|string, amount?: string}>|null  $lines
     *         partial cancellation: whole lines, a quantity of a line or an
     *         amount of a line (e.g. a price reduction); null cancels the whole invoice.
     *         Quantities and amounts are magnitudes, negative lines keep their sign. For lines
     *         with a price including VAT (gross price mode) the amount includes VAT
     * @param  CancellationHistory|null  $history  partial cancellations: what the earlier partial cancellations of
     *         the original cancelled per VAT group, and which groups this one closes, for the cumulative rounding
     *         of the VAT (see CancellationHistory); without it the VAT is rounded per document
     * @param  TotalsCalculator  $calculator  calculator of the VAT alignment with $history, e.g. the one of the Quittio instance
     *
     * @throws InvalidArgumentException e.g. for a history that does not fit the original
     */
    public static function of(
        InvoiceData $original,
        string $number,
        string $strategy = CorrectionPolicy::CANCELLATION_CREDIT_NOTE,
        ?string $reason = null,
        ?Date $issueDate = null,
        ?string $locale = null,
        ?string $title = null,
        ?array $lines = null,
        ?CorrectionPolicy $policy = null,
        ?CancellationHistory $history = null,
        TotalsCalculator $calculator = new En16931Calculator,
    ): InvoiceBuilder {
        if (! in_array($strategy, self::STRATEGIES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown cancellation strategy "%s". Use %s.', $strategy, implode(' or ', self::STRATEGIES)));
        }

        // Data read from XML has no exchange rate: InvoiceBuilder::from() takes over the rate implied
        // by the declared BT-111 / BT-110 of the original (kept in 'totals' below), so that the
        // recalculation reproduces BT-111 (BR-53), and drops it once lines or the VAT accounting
        // currency of the cancellation change.
        $locale ??= $original->meta->locale ?? 'en';
        // A credit note is reversed by an invoice (380) with the same positive amounts in both
        // strategies: a second credit note or a negative credit note would credit twice.
        $reversesCredit = $original->isCreditNote();
        $negative = ! $reversesCredit && $strategy === CorrectionPolicy::CANCELLATION_NEGATIVE_INVOICE;
        $selfBilled = $original->typeCode instanceof InvoiceTypeCode && $original->typeCode->isSelfBilled();
        $translator = Translations::translator();
        // The cancellation of a self-billed invoice is itself issued by the buyer (section 14 (2)
        // sentence 5 UStG) and refers to the original as "Gutschrift", not as "Rechnung".
        $title ??= $policy?->cancellationTitle($selfBilled, $locale);
        $note = $translator->get($selfBilled ? 'quittio::labels.cancellation_note_self_billed' : 'quittio::labels.cancellation_note', [
            'number' => $original->number,
            'date' => (new IntlFormatter)->date($original->issueDate, $locale),
        ], $locale);
        $reasonNote = $reason !== null && $reason !== '' ? ' '.$translator->get('quittio::labels.cancellation_reason', ['reason' => $reason], $locale) : '';

        $selected = $lines === null ? $original->lines : self::select($original, $lines);
        $allowances = $lines === null ? $original->allowances : [];
        $charges = $lines === null ? $original->charges : [];

        $presentation = $title === null ? $original->presentation : ($original->presentation ?? new Presentation)->with(['title' => $title]);

        // Regulatory and tax notes of the original (e.g. retention or exemption hints) still apply.
        $notes = [new Note($note.$reasonNote, NoteSubjectCode::GeneralInformation)];
        foreach ($original->notes as $existing) {
            if (in_array($existing->subjectCode, [NoteSubjectCode::RegulatoryInformation, NoteSubjectCode::TaxDeclaration], true)) {
                $notes[] = $existing;
            }
        }

        // A full cancellation reverses exactly what was invoiced, including the prepaid amount
        // (BT-113), the rounding amount (BT-114) and amounts collected for third parties
        // (BG-DEX-09), so that the amounts due match. A partial cancellation only reverses lines.
        $prepaid = $lines === null ? $original->prepaidAmount : null;
        $rounding = $lines === null ? $original->roundingAmount : null;

        $cancellation = $original->with([
            'number' => $number,
            'issueDate' => $issueDate ?? Date::today($original->meta->timezone),
            'typeCode' => match (true) {
                $negative || $reversesCredit => $selfBilled ? InvoiceTypeCode::SelfBilledInvoice : InvoiceTypeCode::CommercialInvoice,
                default => $selfBilled ? InvoiceTypeCode::SelfBilledCreditNote : InvoiceTypeCode::CreditNote,
            },
            'precedingInvoices' => [new PrecedingInvoiceReference($original->number, $original->issueDate)],
            'notes' => $notes,
            'dueDate' => null,
            'paymentTerms' => new PaymentTerms(text: $translator->get('quittio::labels.cancellation_terms', [], $locale)),
            'lines' => array_map(static fn (InvoiceLine $line): InvoiceLine => self::line($line, $negative), $selected),
            'allowances' => $negative ? array_map(self::documentAllowanceCharge(...), $allowances) : $allowances,
            'charges' => $negative ? array_map(self::documentAllowanceCharge(...), $charges) : $charges,
            'prepaidAmount' => $negative ? self::negate($prepaid) : $prepaid,
            'roundingAmount' => $negative ? self::negate($rounding) : $rounding,
            'xrechnungExtension' => self::xrechnungExtension($original->xrechnungExtension, full: $lines === null, negative: $negative),
            'totals' => $original->totals,
            'vatBreakdown' => $lines === null && $original->totals !== null
                ? array_map(static fn (VatBreakdown $entry): VatBreakdown => $negative ? $entry->with(['taxableAmount' => $entry->taxableAmount->negated(), 'taxAmount' => $entry->taxAmount->negated()]) : $entry, $original->vatBreakdown)
                : [],
            'presentation' => $presentation,
            // InvoiceBuilder::from() keeps the declared amounts only if the recalculation does not
            // reproduce them. Amounts of an original changed after they were declared (fingerprint
            // mismatch) are stale: the flag without fingerprint lets from() recalculate everything.
            'meta' => DeclaredAmountsState::none()->withDeclared(DeclaredAmounts::isStale($original))->applyTo($original->meta),
        ]);

        // Amounts of a stale original are recalculated completely: nothing to align them with.
        if ($lines !== null && $history !== null && ! DeclaredAmounts::isStale($original)) {
            $cancellation = self::alignVat($original, $cancellation, $history, $calculator, $note.' '.$translator->get('quittio::labels.cancellation_vat_aligned', [], $locale).$reasonNote);
        }

        return InvoiceBuilder::fromUsing($cancellation, $calculator);
    }

    /**
     * Partial cancellation with a history: the VAT per group (BT-117) and in accounting currency
     * (BT-111), in gross price mode also the taxable amount per group (BT-116), aligned with the
     * earlier partial cancellations (CancellationHistory) and declared, so that the calculator keeps
     * them (BT-117 within the tolerance of BR-CO-17 for the calculated taxable amounts, BT-111 within
     * 0.01, the net amount of gross price lines within 0.01). $alignedNote replaces the cancellation
     * note if a VAT amount differs from round2(taxable amount × rate).
     */
    private static function alignVat(InvoiceData $original, InvoiceData $cancellation, CancellationHistory $history, TotalsCalculator $calculator, string $alignedNote): InvoiceData
    {
        // Gross price mode: the net amounts follow from the gross amounts cancelled so far per VAT
        // group (CancellationHistory::align()), the calculator distributes them over the lines;
        // the declared net amounts of whole lines of the original would contradict them.
        $gross = (new GrossPriceConverter)->appliesTo($cancellation);
        if ($gross) {
            $cancellation = $cancellation->with(['lines' => array_map(static fn (InvoiceLine $line): InvoiceLine => $line->with(['netAmount' => null]), $cancellation->lines)]);
        }

        // The taxable amounts as the calculator gets them for the cancelled lines (e.g. scaled
        // allowances): a declared VAT breakdown is kept only for these.
        $calculated = $calculator->calculate(InvoiceBuilder::fromUsing($cancellation, $calculator)->build());
        $aligned = $history->align($original, $calculated);
        $history->assertFits($original, $calculated, $aligned['breakdown']);

        $deviates = false;
        foreach ($aligned['breakdown'] as $index => $entry) {
            $deviates = $deviates || ! $entry->taxAmount->isEqualTo($calculated->vatBreakdown[$index]->taxAmount);
        }

        $state = DeclaredAmountsState::fromMeta($cancellation->meta)->withVatAligned();
        $meta = $cancellation->meta;
        if ($aligned['taxInTaxCurrency'] !== null) {
            // At the exchange rate of the original, also when implied by its declared amounts.
            $state = $state->withVatInTaxCurrency($aligned['taxInTaxCurrency']);
            $meta = $meta->with(['exchangeRate' => $original->withImpliedExchangeRate()->meta->exchangeRate]);
        }
        $meta = $state->applyTo($meta);

        $notes = $cancellation->notes;
        if ($deviates) {
            $notes[0] = $notes[0]->with(['content' => $alignedNote]);
        }

        return $cancellation->with([
            'vatBreakdown' => $aligned['breakdown'],
            'notes' => $notes,
            'meta' => $meta,
        ]);
    }

    /**
     * @param  list<array{line: string, quantity?: BigDecimal|int|string, amount?: string}>  $requested
     * @return list<InvoiceLine>
     */
    private static function select(InvoiceData $original, array $requested): array
    {
        if ($requested === []) {
            throw new InvalidArgumentException('A partial cancellation needs at least one line.');
        }
        if ($original->allowances !== [] || $original->charges !== []) {
            // Document level allowances and charges cannot be split reliably onto single lines.
            throw new InvalidArgumentException('Partial cancellations of invoices with document level allowances or charges are not supported. Cancel the invoice fully and issue a new one.');
        }

        $byId = [];
        foreach ($original->lines as $line) {
            $byId[$line->id] = $line;
        }

        $selected = [];
        $seen = [];
        foreach ($requested as $entry) {
            $id = $entry['line'];
            $line = $byId[$id] ?? throw new InvalidArgumentException(sprintf('The invoice has no line "%s".', $id));
            if (isset($seen[$id])) {
                throw new InvalidArgumentException(sprintf('Line "%s" is listed more than once.', $id));
            }
            $seen[$id] = true;

            $quantity = $entry['quantity'] ?? null;
            $amount = $entry['amount'] ?? null;
            $selected[] = match (true) {
                $quantity !== null && $amount !== null => throw new InvalidArgumentException(sprintf('Line "%s": give either a quantity or an amount.', $id)),
                $quantity !== null => self::partialQuantity($line, Decimal::of($quantity), $original->currency),
                $amount !== null => self::partialAmount($line, Amount::of($amount, $original->currency)),
                default => $line,
            };
        }

        return $selected;
    }

    private static function partialQuantity(InvoiceLine $line, BigDecimal $quantity, string $currency): InvoiceLine
    {
        if ($line->subLines !== []) {
            throw new InvalidArgumentException(sprintf('Line "%s" has sub lines and can only be cancelled as a whole.', $line->id));
        }
        if (! $quantity->isPositive() || $quantity->isGreaterThan($line->quantity->abs())) {
            throw new InvalidArgumentException(sprintf('Line "%s": the quantity must be greater than 0 and at most %s.', $line->id, $line->quantity->abs()->toString()));
        }

        $ratio = $quantity->dividedBy($line->quantity->abs(), 10, RoundingMode::HalfUp);
        $scale = static fn (?Money $money): ?Money => $money === null
            ? null
            : Amount::of($money->getAmount()->multipliedBy($ratio)->toScale(Amount::SCALE, RoundingMode::HalfUp), $currency);

        // The requested quantity is a magnitude: a negative line (e.g. a discount or a
        // return) keeps its sign, so that the cancellation reverses it.
        return $line->with([
            'quantity' => $line->quantity->isNegative() ? $quantity->negated() : $quantity,
            'netAmount' => null,
            'allowances' => array_map(static fn (LineAllowanceCharge $a): LineAllowanceCharge => $a->with(['amount' => $scale($a->amount), 'baseAmount' => $scale($a->baseAmount)]), $line->allowances),
            'charges' => array_map(static fn (LineAllowanceCharge $c): LineAllowanceCharge => $c->with(['amount' => $scale($c->amount), 'baseAmount' => $scale($c->baseAmount)]), $line->charges),
        ]);
    }

    private static function partialAmount(InvoiceLine $line, Money $amount): InvoiceLine
    {
        $priceInclVat = $line->price->priceInclVat;
        if ($priceInclVat !== null) {
            return self::partialGrossAmount($line, $amount, $priceInclVat);
        }

        $net = $line->netAmount ?? throw new InvalidArgumentException('Partial cancellations by amount need a calculated original invoice.');
        if (! $amount->isPositive() || $amount->isGreaterThan($net->abs())) {
            throw new InvalidArgumentException(sprintf('Line "%s": the amount must be greater than 0 and at most %s.', $line->id, Amount::format($net->abs())));
        }

        // One unit at the cancelled amount, same item and VAT treatment. The amount is a
        // magnitude: for a negative line the unit is negative, the price stays positive (BR-27).
        return $line->with([
            'quantity' => $net->isNegative() ? BigDecimal::one()->negated() : BigDecimal::one(),
            'unit' => UnitCode::Unit,
            'price' => new Price(netPrice: $amount->getAmount()),
            'netAmount' => null,
            'allowances' => [],
            'charges' => [],
            'subLines' => [],
        ]);
    }

    /**
     * Gross price mode: the amount includes VAT and is compared with the gross line amount, the
     * line keeps its price including VAT so that all lines of the cancellation stay in gross mode.
     */
    private static function partialGrossAmount(InvoiceLine $line, Money $amount, BigDecimal $priceInclVat): InvoiceLine
    {
        $gross = LineCalculator::baseAmount($line->quantity, $priceInclVat, $line->price->baseQuantity);
        if (! $amount->isPositive() || $amount->getAmount()->isGreaterThan($gross->abs())) {
            throw new InvalidArgumentException(sprintf('Line "%s": the amount including VAT must be greater than 0 and at most %s.', $line->id, $gross->abs()->toScale(Amount::SCALE)->toString()));
        }

        return $line->with([
            'quantity' => $gross->isNegative() ? BigDecimal::one()->negated() : BigDecimal::one(),
            'unit' => UnitCode::Unit,
            'price' => new Price(netPrice: null, priceInclVat: $amount->getAmount()),
            'netAmount' => null,
            'allowances' => [],
            'charges' => [],
            'subLines' => [],
        ]);
    }

    /**
     * Amounts collected for third parties (BG-DEX-09) belong to the whole invoice: a full
     * cancellation reverses them, a partial cancellation leaves them out.
     */
    private static function xrechnungExtension(?XRechnungExtensionData $extension, bool $full, bool $negative): ?XRechnungExtensionData
    {
        if ($extension === null || ($full && ! $negative)) {
            return $extension;
        }

        return $extension->with([
            'thirdPartyPayments' => $full
                ? array_map(static fn (ThirdPartyPayment $payment): ThirdPartyPayment => $payment->with(['amount' => $payment->amount->negated()]), $extension->thirdPartyPayments)
                : [],
        ]);
    }

    /**
     * The declared line net amount is kept (negated with the line), see the class description.
     */
    private static function line(InvoiceLine $line, bool $negative): InvoiceLine
    {
        if (! $negative) {
            return $line->with(['subLines' => array_map(static fn (InvoiceLine $sub): InvoiceLine => self::line($sub, false), $line->subLines)]);
        }

        return $line->with([
            'quantity' => $line->quantity->negated(),
            'netAmount' => self::negate($line->netAmount),
            'allowances' => array_map(self::lineAllowanceCharge(...), $line->allowances),
            'charges' => array_map(self::lineAllowanceCharge(...), $line->charges),
            'subLines' => array_map(static fn (InvoiceLine $sub): InvoiceLine => self::line($sub, true), $line->subLines),
        ]);
    }

    /**
     * @template T of LineAllowanceCharge
     *
     * @param  T  $entry
     * @return T
     */
    private static function lineAllowanceCharge(LineAllowanceCharge $entry): LineAllowanceCharge
    {
        return $entry->with(['amount' => self::negate($entry->amount), 'baseAmount' => self::negate($entry->baseAmount)]);
    }

    /**
     * @template T of DocumentAllowanceCharge
     *
     * @param  T  $entry
     * @return T
     */
    private static function documentAllowanceCharge(DocumentAllowanceCharge $entry): DocumentAllowanceCharge
    {
        return $entry->with(['amount' => self::negate($entry->amount), 'baseAmount' => self::negate($entry->baseAmount)]);
    }

    private static function negate(?Money $money): ?Money
    {
        return $money?->negated();
    }
}
