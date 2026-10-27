<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use Quittio\Core\View\Model\AllowanceChargeView;
use Quittio\Core\View\Model\AttachmentView;
use Quittio\Core\View\Model\DeliveryView;
use Quittio\Core\View\Model\DesignView;
use Quittio\Core\View\Model\DocumentInfoView;
use Quittio\Core\View\Model\DocumentView;
use Quittio\Core\View\Model\LineGroupView;
use Quittio\Core\View\Model\LineView;
use Quittio\Core\View\Model\MetaView;
use Quittio\Core\View\Model\NoteView;
use Quittio\Core\View\Model\PartyView;
use Quittio\Core\View\Model\PayeeView;
use Quittio\Core\View\Model\PaymentTermsView;
use Quittio\Core\View\Model\PaymentView;
use Quittio\Core\View\Model\PrecedingInvoiceView;
use Quittio\Core\View\Model\ReferenceView;
use Quittio\Core\View\Model\TaxRepresentativeView;
use Quittio\Core\View\Model\TextsView;
use Quittio\Core\View\Model\TotalsView;
use Quittio\Core\View\Model\VatRowView;
use Quittio\Core\View\Model\ViewList;

/**
 * The only object templates receive. Every value is available
 * formatted ($value->formatted or {{ $value }}) and raw ($value->raw).
 *
 * Templates contain no calculation logic; all values are calculated beforehand.
 *
 * The nodes below are typed (Quittio\Core\View\Model): IDEs and static analysis know that
 * $invoice->seller->address->lines is a ViewList<string> and $invoice->totals->amountDue a
 * FormattedValue. Object and array syntax keep reading the same values.
 *
 * @property-read string $number Invoice number (BT-1)
 * @property-read string $numberLabel Label of the number ("Invoice number"; documents: "Number")
 * @property-read string $dateLabel Label of the date ("Invoice date"; documents: "Date")
 * @property-read string $title Document title
 * @property-read FormattedValue $issueDate Issue date (BT-2)
 * @property-read ?FormattedValue $dueDate Due date (BT-9)
 * @property-read ?FormattedValue $servicePeriod Invoicing period (BG-14), delivery date (BT-72) or the period of the lines
 * @property-read string $servicePeriodLabel "Delivery date" for a single date, otherwise "Service period"
 * @property-read ?FormattedValue $deliveryDate BT-72 when an invoicing period is also set
 * @property-read string $currency Invoice currency (BT-5)
 * @property-read DocumentView $document Type and kind of the document
 * @property-read ?DocumentInfoView $documentInfo Only for documents (quotes, reminders …)
 * @property-read PartyView $seller Seller (BG-4)
 * @property-read PartyView $buyer Buyer (BG-7)
 * @property-read PartyView $issuer The party in letterhead, sender line and footer
 * @property-read PartyView $recipient The party in the address field
 * @property-read ?PayeeView $payee Payee (BG-10)
 * @property-read ?TaxRepresentativeView $taxRepresentative Seller tax representative (BG-11)
 * @property-read ?DeliveryView $delivery Delivery information (BG-13)
 * @property-read ViewList<ReferenceView> $references Filled document references
 * @property-read ViewList<PrecedingInvoiceView> $precedingInvoices Preceding invoices (BG-3)
 * @property-read ViewList<LineGroupView> $lineGroups Lines grouped for display
 * @property-read ViewList<LineView> $lines All top-level lines (BG-25)
 * @property-read ViewList<AllowanceChargeView> $allowances Document allowances (BG-20)
 * @property-read ViewList<AllowanceChargeView> $charges Document charges (BG-21)
 * @property-read ViewList<VatRowView> $vatRows VAT breakdown (BG-23)
 * @property-read ?TotalsView $totals Document totals (BG-22)
 * @property-read ?PaymentView $payment Payment instructions (BG-16)
 * @property-read ?PaymentTermsView $paymentTerms Payment terms (BT-20) and due date
 * @property-read ViewList<string> $legalNotes Exemption reasons and regulatory notes
 * @property-read ViewList<NoteView> $notes Other notes (BG-1)
 * @property-read ViewList<AttachmentView> $attachments Attached documents (BG-24)
 * @property-read TextsView $texts Salutation, intro and closing text
 * @property-read ViewList<string> $labels All labels of the locale with the overrides of the presentation
 * @property-read DesignView $design Design values
 * @property-read MetaView $meta Locale, custom values and source
 */
final class InvoiceViewModel extends ViewNode
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $aliases  view model nodes that stand for others, e.g. issuer => seller
     */
    public function __construct(array $data, ?AccessLog $log = null, array $aliases = [])
    {
        $log ??= new AccessLog;
        $log->bind($data, $aliases);
        parent::__construct($data, $log);
    }

    /**
     * Document title (e.g. "Invoice", "Credit note") for PDF metadata and file names.
     */
    public function title(): string
    {
        $title = $this->data['title'] ?? null;

        return is_string($title) ? $title : '';
    }

    /**
     * Log of the fields the template read, which the mandatory field guard checks after rendering.
     */
    public function accessLog(): AccessLog
    {
        return $this->log;
    }

    /**
     * Translated label; per invoice overrides from the presentation data win.
     *
     * @param  array<string, string>  $replace
     */
    public function label(string $key, array $replace = []): string
    {
        $labels = $this->data['labels'] ?? [];
        $text = is_array($labels) && isset($labels[$key]) && is_string($labels[$key]) ? $labels[$key] : $key;
        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, $value, $text);
        }

        return $text;
    }
}
