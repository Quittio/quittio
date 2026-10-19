<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal;

use Quittio\Core\View\AccessLog;
use Quittio\Core\View\InvoiceViewModel;
use Quittio\Core\View\Model\AccountView;
use Quittio\Core\View\Model\AddressView;
use Quittio\Core\View\Model\AllowanceChargeView;
use Quittio\Core\View\Model\AttachmentView;
use Quittio\Core\View\Model\AttributeView;
use Quittio\Core\View\Model\CardView;
use Quittio\Core\View\Model\ContactView;
use Quittio\Core\View\Model\DeliveryView;
use Quittio\Core\View\Model\DescribedAmountView;
use Quittio\Core\View\Model\DescriptionView;
use Quittio\Core\View\Model\DesignView;
use Quittio\Core\View\Model\DirectDebitView;
use Quittio\Core\View\Model\DiscountView;
use Quittio\Core\View\Model\DocumentInfoView;
use Quittio\Core\View\Model\DocumentView;
use Quittio\Core\View\Model\DunningView;
use Quittio\Core\View\Model\InterestView;
use Quittio\Core\View\Model\LineAllowanceChargeView;
use Quittio\Core\View\Model\LineGroupView;
use Quittio\Core\View\Model\LineOptionsView;
use Quittio\Core\View\Model\LineView;
use Quittio\Core\View\Model\MetaView;
use Quittio\Core\View\Model\NoteView;
use Quittio\Core\View\Model\OpenItemView;
use Quittio\Core\View\Model\PackageView;
use Quittio\Core\View\Model\PartyView;
use Quittio\Core\View\Model\PayeeView;
use Quittio\Core\View\Model\PaymentSlipSectionView;
use Quittio\Core\View\Model\PaymentSlipView;
use Quittio\Core\View\Model\PaymentTermsView;
use Quittio\Core\View\Model\PaymentView;
use Quittio\Core\View\Model\PrecedingInvoiceView;
use Quittio\Core\View\Model\QrCodeView;
use Quittio\Core\View\Model\ReferenceView;
use Quittio\Core\View\Model\StatementView;
use Quittio\Core\View\Model\TaxRepresentativeView;
use Quittio\Core\View\Model\TextsView;
use Quittio\Core\View\Model\TotalsView;
use Quittio\Core\View\Model\UnitView;
use Quittio\Core\View\Model\VatRowView;
use Quittio\Core\View\Model\ViewList;
use Quittio\Core\View\ViewNode;

/**
 * Which typed node a nested array of the view model becomes: per node class and key, a node
 * class, a list of entries of a type ("LineView[]", "[]" for plain values) or nothing (a
 * plain ViewNode, as for every key of a class that is not listed).
 *
 * The data of the view model stays the nested array it always was; the types only decide the
 * class of the node objects that templates receive. The @property-read types of the node
 * classes describe the same structure (ViewModelTypesTest checks both against each other).
 *
 * @internal
 */
final class NodeTypes
{
    private const array CHILDREN = [
        InvoiceViewModel::class => [
            'document' => DocumentView::class,
            'documentInfo' => DocumentInfoView::class,
            'seller' => PartyView::class,
            'buyer' => PartyView::class,
            'issuer' => PartyView::class,
            'recipient' => PartyView::class,
            'payee' => PayeeView::class,
            'taxRepresentative' => TaxRepresentativeView::class,
            'delivery' => DeliveryView::class,
            'references' => ReferenceView::class.'[]',
            'precedingInvoices' => PrecedingInvoiceView::class.'[]',
            'lineGroups' => LineGroupView::class.'[]',
            'lines' => LineView::class.'[]',
            'allowances' => AllowanceChargeView::class.'[]',
            'charges' => AllowanceChargeView::class.'[]',
            'vatRows' => VatRowView::class.'[]',
            'totals' => TotalsView::class,
            'payment' => PaymentView::class,
            'paymentTerms' => PaymentTermsView::class,
            'legalNotes' => '[]',
            'notes' => NoteView::class.'[]',
            'attachments' => AttachmentView::class.'[]',
            'texts' => TextsView::class,
            'labels' => '[]',
            'design' => DesignView::class,
            'meta' => MetaView::class,
        ],
        PartyView::class => [
            'address' => AddressView::class,
            'contact' => ContactView::class,
            'identifiers' => '[]',
        ],
        AddressView::class => [
            'lines' => '[]',
        ],
        TaxRepresentativeView::class => [
            'address' => AddressView::class,
        ],
        DeliveryView::class => [
            'address' => AddressView::class,
        ],
        LineGroupView::class => [
            'lines' => LineView::class.'[]',
        ],
        LineView::class => [
            'description' => DescriptionView::class,
            'unit' => UnitView::class,
            'attributes' => AttributeView::class.'[]',
            'allowances' => LineAllowanceChargeView::class.'[]',
            'charges' => LineAllowanceChargeView::class.'[]',
            'subLines' => LineView::class.'[]',
            'serialNumbers' => '[]',
        ],
        TotalsView::class => [
            'thirdPartyPayments' => DescribedAmountView::class.'[]',
        ],
        PaymentView::class => [
            'accounts' => AccountView::class.'[]',
            'card' => CardView::class,
            'directDebit' => DirectDebitView::class,
            'qrCode' => QrCodeView::class,
        ],
        QrCodeView::class => [
            'slip' => PaymentSlipView::class,
        ],
        PaymentSlipView::class => [
            'sections' => PaymentSlipSectionView::class.'[]',
            'receiptSections' => PaymentSlipSectionView::class.'[]',
        ],
        PaymentSlipSectionView::class => [
            'lines' => '[]',
        ],
        PaymentTermsView::class => [
            'lines' => '[]',
            'discounts' => DiscountView::class.'[]',
        ],
        DesignView::class => [
            'foldMarks' => '[]',
            'footerColumns' => '[][]',
        ],
        DocumentInfoView::class => [
            'packages' => PackageView::class.'[]',
            'lines' => LineOptionsView::class.'[]',
            'dunning' => DunningView::class,
            'statement' => StatementView::class,
        ],
        LineOptionsView::class => [
            'serialNumbers' => '[]',
        ],
        DunningView::class => [
            'openItems' => OpenItemView::class.'[]',
            'fees' => DescribedAmountView::class.'[]',
            'interest' => InterestView::class.'[]',
        ],
        StatementView::class => [
            'items' => OpenItemView::class.'[]',
        ],
    ];

    /**
     * The type of the array under $key of a node of $class; "" for a plain node.
     */
    public static function child(string $class, string|int $key): string
    {
        return is_string($key) ? self::CHILDREN[$class][$key] ?? '' : '';
    }

    /**
     * The node of $type for $data: a typed node, a ViewList, or a plain ViewNode.
     *
     * @param  array<string|int, mixed>  $data
     */
    public static function node(string $type, array $data, AccessLog $log, string $path): ViewNode
    {
        if (str_ends_with($type, '[]')) {
            return new ViewList($data, $log, $path, substr($type, 0, -2));
        }
        if ($type !== '' && is_subclass_of($type, ViewNode::class) && $type !== InvoiceViewModel::class) {
            return new $type($data, $log, $path);
        }

        return new ViewNode($data, $log, $path);
    }
}
