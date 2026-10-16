<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Builder;

use Closure;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Support\Amount;
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

    /**
     * @param  TBuilder  $builder
     */
    public function __construct(private InvoiceBuilder $builder) {}

    /**
     * Small amount invoice of at most 250 EUR gross (section 33 UStDV; the limit of the issue date).
     *
     * @return TBuilder
     */
    public function smallAmount(): InvoiceBuilder
    {
        return $this->builder->flag(Flags::SMALL_AMOUNT);
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
     * Final invoice (section 14 (5) sentence 2 UStG) that deducts a paid advance invoice with its
     * net amounts and VAT per rate. The full service is invoiced with its full VAT, the advance
     * payment reduces the amount due (BT-113) and is referenced (BG-3) and itemised (BT-22).
     *
     * @return TBuilder
     *
     * @throws InvalidArgumentException for a document that is no paid advance invoice in the currency of the builder
     */
    public function deductAdvance(InvoiceData $advance, string $locale = 'de'): InvoiceBuilder
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
        // The currency set so far; the builder has no getter for it.
        $currency = Closure::bind(fn (): string => $this->currency, $this->builder, InvoiceBuilder::class)();
        if ($advance->currency !== $currency) {
            throw new InvalidArgumentException(sprintf('The advance invoice %s is in %s, the final invoice in %s.', $advance->number, $advance->currency, $currency));
        }

        // The whole note in one language; amounts and dates in the requested locale if the
        // note is in its language (e.g. "de_AT"), otherwise in the language of the note.
        $language = LegalTexts::localeOf(LegalTexts::ADVANCE_DEDUCTION, $locale);
        $format = LegalTexts::language($locale) === $language ? $locale : $language;
        $formatter = new IntlFormatter;
        $amounts = [];
        foreach ($advance->vatBreakdown as $row) {
            $net = $formatter->money($row->taxableAmount, $format);
            $amounts[] = $row->category === VatCategory::StandardRate && $row->rate !== null
                ? LegalTexts::text(LegalTexts::ADVANCE_DEDUCTION_TAXED, $language, ['net' => $net, 'rate' => $formatter->percent($row->rate, $format), 'vat' => $formatter->money($row->taxAmount, $format)])
                : LegalTexts::text(LegalTexts::ADVANCE_DEDUCTION_UNTAXED, $language, ['net' => $net]);
        }

        $text = LegalTexts::text(LegalTexts::ADVANCE_DEDUCTION, $language, [
            'number' => $advance->number,
            'date' => $formatter->date($advance->issueDate, $format),
            'amounts' => implode('; ', $amounts),
        ]);

        return $this->builder->precedingInvoice($advance->number, $advance->issueDate)
            // The amount received: gross total plus a cash rounding (BT-114) of the advance invoice.
            ->addPrepaid(Amount::format($advance->totals->roundingAmount === null ? $advance->totals->grossTotal : $advance->totals->grossTotal->plus($advance->totals->roundingAmount)))
            ->note($text, NoteSubjectCode::RegulatoryInformation);
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
