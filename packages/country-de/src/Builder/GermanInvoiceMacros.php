<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Builder;

use InvalidArgumentException;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Support\Amount;
use Quittio\Core\View\IntlFormatter;
use Quittio\Country\DE\Support\Flags;
use Quittio\Country\DE\Texts\LegalTexts;

/**
 * Builder helpers for German special cases (specification 8.3):
 *
 *     InvoiceBuilder::make()->smallAmount()->...
 *     InvoiceBuilder::make()->propertyRelatedService()->...
 *     InvoiceBuilder::make()->selfBilled()->...
 *     InvoiceBuilder::make()->deductAdvance($advanceInvoice)->...
 *
 * The $locale of the notes selects their language; locales without a text of the pack get the
 * German text.
 */
final class GermanInvoiceMacros
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

    public static function register(): void
    {
        if (InvoiceBuilder::hasMacro('smallAmount')) {
            return;
        }

        InvoiceBuilder::macro('smallAmount', function (): InvoiceBuilder {
            return $this->flag(Flags::SMALL_AMOUNT);
        });

        InvoiceBuilder::macro('propertyRelatedService', function (string $locale = 'de'): InvoiceBuilder {
            return $this->flag(Flags::PROPERTY_RELATED_SERVICE)
                ->note(LegalTexts::text(LegalTexts::PROPERTY_RETENTION, $locale), NoteSubjectCode::RegulatoryInformation);
        });

        // Intra-community supply of a new vehicle (section 14a (4) UStG): the vehicle details are
        // the line attributes (DE-USTG-07), the lines use VatPreset::IntraCommunitySupply (DE-USTG-13).
        InvoiceBuilder::macro('newVehicle', function (string $locale = 'de'): InvoiceBuilder {
            return $this->flag(Flags::NEW_VEHICLE)
                ->note(LegalTexts::text(LegalTexts::NEW_VEHICLE, $locale), NoteSubjectCode::RegulatoryInformation);
        });

        InvoiceBuilder::macro('selfBilled', function (string $locale = 'de'): InvoiceBuilder {
            return $this->type(InvoiceTypeCode::SelfBilledInvoice)
                ->note(LegalTexts::text(LegalTexts::SELF_BILLING, $locale), NoteSubjectCode::RegulatoryInformation);
        });

        // Final invoice (section 14 (5) sentence 2 UStG): deducts a paid advance invoice with its
        // net amounts and VAT per rate. The full service is invoiced with its full VAT, the advance
        // payment reduces the amount due (BT-113) and is referenced (BG-3) and itemised (BT-22).
        InvoiceBuilder::macro('deductAdvance', function (InvoiceData $advance, string $locale = 'de'): InvoiceBuilder {
            if (! in_array($advance->typeCode, GermanInvoiceMacros::ADVANCE_TYPES, true)) {
                throw new InvalidArgumentException(sprintf('The document %s (type code %s) cannot be deducted as an advance invoice: only type codes %s can.', $advance->number, $advance->typeCodeValue(), implode(', ', array_map(static fn (InvoiceTypeCode $type): string => $type->value, GermanInvoiceMacros::ADVANCE_TYPES))));
            }
            if ($advance->totals === null) {
                throw new InvalidArgumentException(sprintf('The advance invoice %s must be calculated before it is deducted.', $advance->number));
            }
            // A negative invoice cancels an invoice (cancellation strategy negative_invoice).
            if (! $advance->totals->grossTotal->isPositive()) {
                throw new InvalidArgumentException(sprintf('The advance invoice %s must have a positive gross total.', $advance->number));
            }
            // Macros run in the scope of the builder.
            $currency = $this->currency;
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

            return $this->precedingInvoice($advance->number, $advance->issueDate)
                // The amount received: gross total plus a cash rounding (BT-114) of the advance invoice.
                ->addPrepaid(Amount::format($advance->totals->roundingAmount === null ? $advance->totals->grossTotal : $advance->totals->grossTotal->plus($advance->totals->roundingAmount)))
                ->note($text, NoteSubjectCode::RegulatoryInformation);
        });

        InvoiceBuilder::macro('consumerDefaultNotice', function (string $locale = 'de'): InvoiceBuilder {
            return $this->note(LegalTexts::text(LegalTexts::CONSUMER_DEFAULT, $locale), NoteSubjectCode::PaymentInformation);
        });
    }
}
