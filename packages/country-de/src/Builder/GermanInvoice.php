<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Builder;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Closure;
use DateTimeInterface;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\LineBuilder;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Calculation\LargestRemainder;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Totals\VatBreakdown;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Validation\Plausibility\VatIdFormat;
use Quittio\Core\View\IntlFormatter;
use Quittio\Country\DE\Support\Flags;
use Quittio\Country\DE\Texts\LegalTexts;

/**
 * German special cases of an invoice, reached with the builder macro de():
 *
 *     InvoiceBuilder::make()->de()->smallAmount()->number(…)
 *     Quittio::invoice()->de()->deductAdvance($advanceInvoice)->…
 *
 * Every method returns the builder, so the chain continues there. The $locale of the notes
 * selects their language; locales without a text of the pack get the German text.
 *
 * @template TBuilder of InvoiceBuilder
 */
final readonly class GermanInvoice
{
    /**
     * Type codes of documents that can be deducted as advance invoices: invoices that request a
     * payment, not credit notes, debit notes, corrected invoices or final invoices.
     *
     * @var list<InvoiceTypeCode>
     */
    public const array ADVANCE_TYPES = [
        InvoiceTypeCode::CommercialInvoice,
        InvoiceTypeCode::PrepaymentInvoice,
        InvoiceTypeCode::PartialInvoice,
        InvoiceTypeCode::PartialConstructionInvoice,
        InvoiceTypeCode::SelfBilledInvoice,
    ];

    /** deductAdvance(): the advance payment reduces the amount due (BT-113). */
    public const string PREPAID = 'prepaid';

    /** deductAdvance(): a negative line per VAT rate, the invoice shows the VAT of the remainder. */
    public const string REMAINDER = 'remainder';

    /** deductAdvance(): the advance invoice was paid in full. */
    public const string RECEIVED_IN_FULL = 'full';

    /**
     * @param  TBuilder  $builder
     */
    public function __construct(private InvoiceBuilder $builder) {}

    /**
     * Small amount invoice of at most 250 EUR gross (section 33 UStDV; the limit of the supply date).
     *
     * @return TBuilder
     */
    public function smallAmount(): InvoiceBuilder
    {
        return $this->builder->flag(Flags::SMALL_AMOUNT);
    }

    /**
     * Invoice of a small business that states its small business identification number
     * (Kleinunternehmer-Identifikationsnummer, suffix "EX") instead of the tax number (section
     * 34a sentence 1 no. 2 UStDV). The number is no VAT identifier: it goes to the seller's tax
     * registration (BT-32), not to BT-31. Sets the small business flag.
     *
     * @return TBuilder
     *
     * @throws InvalidArgumentException for a number without the suffix "EX"
     */
    public function smallBusinessId(string $id): InvoiceBuilder
    {
        if (! VatIdFormat::isSmallBusinessId($id)) {
            throw new InvalidArgumentException(sprintf('"%s" is no small business identification number: it must start with the country code and end with "EX", e.g. DE123456789EX.', $id));
        }

        return $this->builder->seller(static fn (PartyBuilder $seller): PartyBuilder => $seller->taxNumber($id))
            ->flag(Flags::SMALL_BUSINESS);
    }

    /**
     * Prepayment invoice (type code 386): the note "Anzahlungsrechnung. Die Leistung wird
     * voraussichtlich am … / im … erbracht." with the expected date or, as "2027-03", month of the
     * service (section 14.8 (4) sentence 3 UStAE). Without one, the note that the date is not
     * agreed yet (serviceDateOpen()). A payment already received is stated with its date: the
     * note "Die Anzahlung wurde am … vereinnahmt." and the VAT point date BT-7, to which the
     * mapping table of KoSIT and FeRD assigns the date of receipt (section 14 (4) sentence 1
     * no. 6 UStG).
     *
     * @return TBuilder
     */
    public function advance(Date|DateTimeInterface|string|null $expectedService, Date|DateTimeInterface|string|null $receivedOn = null, string $locale = 'de'): InvoiceBuilder
    {
        $this->builder->type(InvoiceTypeCode::PrepaymentInvoice);
        $formatter = new IntlFormatter;
        if ($expectedService === null) {
            $this->serviceDateOpen($locale);
        } elseif (is_string($expectedService) && preg_match('/^(\d{4})-(\d{2})$/', $expectedService, $month) === 1) {
            $language = LegalTexts::localeOf(LegalTexts::PREPAYMENT_MONTH, $locale);
            $name = Date::of((int) $month[1], (int) $month[2], 1)->toCarbon('UTC')->settings(['locale' => $language])->isoFormat('MMMM YYYY');
            $this->builder->flag(Flags::EXPECTED_SERVICE_DATE)
                ->note(LegalTexts::text(LegalTexts::PREPAYMENT_MONTH, $locale, ['month' => $name]), NoteSubjectCode::RegulatoryInformation);
        } else {
            $language = LegalTexts::localeOf(LegalTexts::PREPAYMENT, $locale);
            $this->builder->flag(Flags::EXPECTED_SERVICE_DATE)
                ->note(LegalTexts::text(LegalTexts::PREPAYMENT, $locale, ['date' => $formatter->date(Date::parse($expectedService), LegalTexts::language($locale) === $language ? $locale : $language)]), NoteSubjectCode::RegulatoryInformation);
        }
        if ($receivedOn !== null) {
            $received = Date::parse($receivedOn);
            $language = LegalTexts::localeOf(LegalTexts::PREPAYMENT_RECEIVED, $locale);
            $this->builder->taxPointDate($received)
                ->note(LegalTexts::text(LegalTexts::PREPAYMENT_RECEIVED, $locale, ['date' => $formatter->date($received, LegalTexts::language($locale) === $language ? $locale : $language)]), NoteSubjectCode::RegulatoryInformation);
        }

        return $this->builder;
    }

    /**
     * Prepayment invoice whose date of the service is not agreed yet: the note
     * LegalTexts::SERVICE_DATE_OPEN, and DE-USTG-03 does not warn about the missing date
     * (section 14.8 (4) sentence 5 UStAE).
     *
     * @return TBuilder
     */
    public function serviceDateOpen(string $locale = 'de'): InvoiceBuilder
    {
        return $this->builder->flag(Flags::SERVICE_DATE_OPEN)
            ->note(LegalTexts::text(LegalTexts::SERVICE_DATE_OPEN, $locale), NoteSubjectCode::RegulatoryInformation);
    }

    /**
     * Service in connection with a property to a non-business buyer, with the retention note
     * (section 14 (4) no. 9 UStG).
     *
     * @return TBuilder
     */
    public function propertyRelatedService(string $locale = 'de'): InvoiceBuilder
    {
        return $this->builder->flag(Flags::PROPERTY_RELATED_SERVICE)
            ->note(LegalTexts::text(LegalTexts::PROPERTY_RETENTION, $locale), NoteSubjectCode::RegulatoryInformation);
    }

    /**
     * Intra-community supply of a new vehicle (section 14a (4) UStG): the vehicle details are the
     * line attributes (DE-USTG-07), the lines use VatPreset::IntraCommunitySupply (DE-USTG-13).
     *
     * @return TBuilder
     */
    public function newVehicle(string $locale = 'de'): InvoiceBuilder
    {
        return $this->builder->flag(Flags::NEW_VEHICLE)
            ->note(LegalTexts::text(LegalTexts::NEW_VEHICLE, $locale), NoteSubjectCode::RegulatoryInformation);
    }

    /**
     * Self-billed invoice (type code 389) with the note "Gutschrift" (section 14 (4) no. 10 UStG).
     *
     * @return TBuilder
     */
    public function selfBilled(string $locale = 'de'): InvoiceBuilder
    {
        return $this->builder->type(InvoiceTypeCode::SelfBilledInvoice)
            ->note(LegalTexts::text(LegalTexts::SELF_BILLING, $locale), NoteSubjectCode::RegulatoryInformation);
    }

    /**
     * Final invoice (section 14 (5) sentence 2 UStG) that deducts an advance invoice with the net
     * amounts and VAT received per rate. Only advance payments actually received are deducted:
     *
     * - $received: the amount received (gross). Less than the advance invoice: net amount and VAT
     *   are taken out of it in proportion to the VAT rows of the advance invoice (section 14.8 (5)
     *   UStAE). RECEIVED_IN_FULL (or the full amount) deducts the advance invoice as it is. Null
     *   deducts it in full as well, but sets the flag de.advance_receipt_unconfirmed, and
     *   DE-USTG-20 asks to confirm the receipt: an advance not paid must not be deducted, one paid
     *   in part only with that part (section 14.8 (2) and (10) UStAE).
     * - $style: PREPAID invoices the full service with its full VAT, and the advance payment
     *   reduces the amount due (BT-113), referenced (BG-3) and itemised (BT-22, section 14.8 (7)
     *   UStAE; BMF letter of 15 October 2026, marginal no. 3, until 30 June 2030). REMAINDER adds a
     *   negative line per VAT rate with the net amount received, so the invoice states only the
     *   VAT of the remainder (remainder invoice, section 14.8 (11) UStAE); easier for the buyer's
     *   system, but not covered by an e-invoice specific statement of the BMF.
     *
     * @param  string  $style  self::PREPAID or self::REMAINDER
     * @return TBuilder
     *
     * @throws InvalidArgumentException for a document that is no advance invoice in the currency of the
     *         builder, or a received amount that is not greater than zero or exceeds the advance invoice
     */
    public function deductAdvance(InvoiceData $advance, string $locale = 'de', Money|BigDecimal|int|string|null $received = null, string $style = self::PREPAID): InvoiceBuilder
    {
        if (! in_array($advance->typeCode, self::ADVANCE_TYPES, true)) {
            throw new InvalidArgumentException(sprintf('The document %s (type code %s) cannot be deducted as an advance invoice: only type codes %s can.', $advance->number, $advance->typeCodeValue(), implode(', ', array_map(static fn (InvoiceTypeCode $type): string => $type->value, self::ADVANCE_TYPES))));
        }
        if ($advance->totals === null) {
            throw new InvalidArgumentException(sprintf('The advance invoice %s must be calculated before it is deducted.', $advance->number));
        }
        // A negative invoice cancels an invoice (cancellation strategy negative_invoice).
        if (! $advance->totals->grossTotal->isPositive()) {
            throw new InvalidArgumentException(sprintf('The advance invoice %s must have a positive gross total.', $advance->number));
        }
        if (! in_array($style, [self::PREPAID, self::REMAINDER], true)) {
            throw new InvalidArgumentException(sprintf('Unknown style "%s" of the advance deduction: use %s or %s.', $style, self::PREPAID, self::REMAINDER));
        }
        // The currency set so far; the builder has no getter for it.
        $currency = Closure::bind(fn (): string => $this->currency, $this->builder, InvoiceBuilder::class)();
        if ($advance->currency !== $currency) {
            throw new InvalidArgumentException(sprintf('The advance invoice %s is in %s, the final invoice in %s.', $advance->number, $advance->currency, $currency));
        }

        // The amount payable: gross total plus a cash rounding (BT-114) of the advance invoice.
        $payable = $advance->totals->roundingAmount === null ? $advance->totals->grossTotal : $advance->totals->grossTotal->plus($advance->totals->roundingAmount);
        $amount = match (true) {
            $received === null, $received === self::RECEIVED_IN_FULL => $payable,
            $received instanceof Money => $received,
            default => Amount::of(Decimal::of($received), $currency),
        };
        if (! $amount->isPositive() || $amount->isGreaterThan($payable)) {
            throw new InvalidArgumentException(sprintf('The amount received for the advance invoice %s must be greater than 0 and at most %s: advance payments not received are not deducted.', $advance->number, Amount::format($payable)));
        }
        $rows = $amount->isEqualTo($payable)
            ? array_map(static fn (VatBreakdown $row): array => [$row, $row->taxableAmount, $row->taxAmount], $advance->vatBreakdown)
            : self::receivedRows($advance, $amount);

        // The whole note in one language; amounts and dates in the requested locale if the
        // note is in its language (e.g. "de_AT"), otherwise in the language of the note.
        $language = LegalTexts::localeOf(LegalTexts::ADVANCE_DEDUCTION, $locale);
        $format = LegalTexts::language($locale) === $language ? $locale : $language;
        $formatter = new IntlFormatter;
        $amounts = [];
        foreach ($rows as [$row, $net, $vat]) {
            $amounts[] = $row->category === VatCategory::StandardRate && $row->rate !== null
                ? LegalTexts::text(LegalTexts::ADVANCE_DEDUCTION_TAXED, $language, ['net' => $formatter->money($net, $format), 'rate' => $formatter->percent($row->rate, $format), 'vat' => $formatter->money($vat, $format)])
                : LegalTexts::text(LegalTexts::ADVANCE_DEDUCTION_UNTAXED, $language, ['net' => $formatter->money($net, $format)]);
        }

        $replace = ['number' => $advance->number, 'date' => $formatter->date($advance->issueDate, $format), 'amounts' => implode('; ', $amounts)];

        if ($received === null) {
            $this->builder->flag(Flags::ADVANCE_RECEIPT_UNCONFIRMED);
        }
        // An advance settled by self-billing must be deducted as well (section 14 (2) sentence 5
        // UStG); DE-USTG-19 notes when the final invoice is no self-billed invoice.
        if ($advance->typeCode === InvoiceTypeCode::SelfBilledInvoice) {
            $this->builder->flag(Flags::SELF_BILLED_ADVANCE);
        }
        $this->builder->precedingInvoice($advance->number, $advance->issueDate);

        if ($style === self::REMAINDER) {
            $name = LegalTexts::text(LegalTexts::ADVANCE_DEDUCTION_LINE, $language, ['number' => $advance->number, 'date' => $replace['date']]);
            foreach ($rows as [$row, $net]) {
                $this->builder->line(static fn (LineBuilder $line): LineBuilder => $line->name($name)->quantity(-1, UnitCode::Unit)->netPrice($net->getAmount())
                    ->vat($row->category, $row->rate, $row->exemptionReason, $row->exemptionReasonCode));
            }

            return $this->builder->note(LegalTexts::text(LegalTexts::ADVANCE_DEDUCTION_REMAINDER, $language, $replace), NoteSubjectCode::RegulatoryInformation);
        }

        return $this->builder->addPrepaid(Amount::format($amount))
            ->note(LegalTexts::text(LegalTexts::ADVANCE_DEDUCTION, $language, $replace), NoteSubjectCode::RegulatoryInformation);
    }

    /**
     * The VAT rows of an advance invoice paid in part: the amount received distributed over the
     * rows in proportion to their gross amounts (largest remainder), the VAT taken out of each part
     * at its rate (section 14.8 (5) sentences 4 and 5 UStAE).
     *
     * @return list<array{VatBreakdown, Money, Money}> row, net amount and VAT received
     */
    private static function receivedRows(InvoiceData $advance, Money $received): array
    {
        $weights = array_map(static fn (VatBreakdown $row): BigDecimal => $row->taxableAmount->plus($row->taxAmount)->getAmount(), $advance->vatBreakdown);
        $parts = LargestRemainder::distribute($received->getAmount(), $weights, Amount::SCALE);
        $rows = [];
        foreach ($advance->vatBreakdown as $index => $row) {
            $gross = $parts[$index];
            $rate = $row->rate ?? BigDecimal::zero();
            $vat = $row->taxAmount->isZero() ? BigDecimal::zero() : $gross->multipliedBy($rate)->dividedBy($rate->plus(100), Amount::SCALE, RoundingMode::HalfUp);
            $rows[] = [$row, Amount::of($gross->minus($vat), $advance->currency), Amount::of($vat, $advance->currency)];
        }

        return $rows;
    }

    /**
     * Reference to an agreed reduction of the consideration whose amount is not known when the
     * invoice is issued, e.g. a bonus or rebate agreement (section 14 (4) no. 7 UStG): the note
     * LegalTexts::DISCOUNT_AGREEMENT naming $agreement, e.g. "der Bonusvereinbarung vom
     * 01.01.2026". Naming the agreement is enough if its documents can be shown at short notice
     * (section 14.5 (19) UStAE). A cash discount (Skonto) is stated in the payment terms instead.
     *
     * @return TBuilder
     */
    public function discountAgreement(string $agreement, string $locale = 'de'): InvoiceBuilder
    {
        return $this->builder->note(LegalTexts::text(LegalTexts::DISCOUNT_AGREEMENT, $locale, ['agreement' => $agreement]), NoteSubjectCode::RegulatoryInformation);
    }

    /**
     * Notice that a consumer is in default 30 days after the due date (section 286 (3) BGB).
     *
     * @return TBuilder
     */
    public function consumerDefaultNotice(string $locale = 'de'): InvoiceBuilder
    {
        return $this->builder->note(LegalTexts::text(LegalTexts::CONSUMER_DEFAULT, $locale), NoteSubjectCode::PaymentInformation);
    }
}
