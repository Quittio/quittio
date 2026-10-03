<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Lines;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\AllowanceCharge\LineAllowance;
use Quittio\Core\Data\AllowanceCharge\LineCharge;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Extensions\LineExtendedData;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\Presentation\LinePresentation;
use Quittio\Core\Data\References\DocumentReference;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Invoice line (BG-25).
 */
final readonly class InvoiceLine
{
    use Immutable;

    /**
     * @param  list<LineAllowance>  $allowances
     * @param  list<LineCharge>  $charges
     * @param  list<InvoiceLine>  $subLines
     * @param  list<DocumentReference>  $lineReferences
     */
    public function __construct(
        #[Q\Bt('BT-126'), Q\Cardinality('1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:AssociatedDocumentLineDocument/ram:LineID'), Q\Ubl('cbc:ID')]
        public string $id,
        #[Q\Bt('BT-129'), Q\Cardinality('1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity'), Q\Ubl('cbc:InvoicedQuantity')]
        public BigDecimal $quantity,
        #[Q\Bt('BT-130'), Q\Cardinality('1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode'), Q\Ubl('cbc:InvoicedQuantity/@unitCode')]
        public UnitCode|RawCode $unit,
        #[Q\Bt('BG-29'), Q\Cardinality('1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:SpecifiedLineTradeAgreement'), Q\Ubl('cac:Price')]
        public Price $price,
        #[Q\Bt('BG-30'), Q\Cardinality('1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax'), Q\Ubl('cac:Item/cac:ClassifiedTaxCategory')]
        public LineVat $vat,
        #[Q\Bt('BG-31'), Q\Cardinality('1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:SpecifiedTradeProduct'), Q\Ubl('cac:Item')]
        public Item $item,
        #[Q\Bt('BT-127'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:AssociatedDocumentLineDocument/ram:IncludedNote/ram:Content'), Q\Ubl('cbc:Note')]
        public ?string $note = null,
        #[Q\Bt('BT-128'), Q\Cardinality('0..1'), Q\Cii('ram:SpecifiedLineTradeSettlement/ram:AdditionalReferencedDocument[ram:TypeCode="130"]'), Q\Ubl('cac:DocumentReference[cbc:DocumentTypeCode="130"]/cbc:ID')]
        public ?Identifier $objectId = null,
        #[Q\Bt('BT-131'), Q\Cardinality('1'), Q\Level(ProfileLevel::Basic), Q\Computed, Q\Cii('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'), Q\Ubl('cbc:LineExtensionAmount')]
        public ?Money $netAmount = null,
        #[Q\Bt('BT-132'), Q\Cardinality('0..1'), Q\Cii('ram:SpecifiedLineTradeAgreement/ram:BuyerOrderReferencedDocument/ram:LineID'), Q\Ubl('cac:OrderLineReference/cbc:LineID')]
        public ?string $orderLineReference = null,
        #[Q\Bt('BT-133'), Q\Cardinality('0..1'), Q\Cii('ram:SpecifiedLineTradeSettlement/ram:ReceivableSpecifiedTradeAccountingAccount/ram:ID'), Q\Ubl('cbc:AccountingCost')]
        public ?string $buyerAccountingReference = null,
        #[Q\Bt('BG-26'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::Basic), Q\Cii('ram:SpecifiedLineTradeSettlement/ram:BillingSpecifiedPeriod'), Q\Ubl('cac:InvoicePeriod')]
        public ?Period $period = null,
        #[Q\Bt('BG-27'), Q\Cardinality('0..n'), Q\Level(ProfileLevel::Basic), Q\ListOf(LineAllowance::class), Q\Cii('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeAllowanceCharge'), Q\Ubl('cac:AllowanceCharge')]
        public array $allowances = [],
        #[Q\Bt('BG-28'), Q\Cardinality('0..n'), Q\Level(ProfileLevel::Basic), Q\ListOf(LineCharge::class), Q\Cii('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeAllowanceCharge'), Q\Ubl('cac:AllowanceCharge')]
        public array $charges = [],
        #[Q\Internal, Q\ListOf(InvoiceLine::class)]
        public array $subLines = [],
        #[Q\ExtendedOnly, Q\ListOf(DocumentReference::class)]
        public array $lineReferences = [],
        #[Q\ExtendedOnly]
        public ?LineExtendedData $extended = null,
        #[Q\PdfOnly]
        public ?LinePresentation $presentation = null,
    ) {}

    public function hasSubLines(): bool
    {
        return $this->subLines !== [];
    }
}
