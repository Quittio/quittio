<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal;

use Quittio\Core\Calculation\CalculationException;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\View\Internal\Sections\AllowancesSection;
use Quittio\Core\View\Internal\Sections\DesignSection;
use Quittio\Core\View\Internal\Sections\DocumentSection;
use Quittio\Core\View\Internal\Sections\HeaderSection;
use Quittio\Core\View\Internal\Sections\LinesSection;
use Quittio\Core\View\Internal\Sections\NotesSection;
use Quittio\Core\View\Internal\Sections\PartiesSection;
use Quittio\Core\View\Internal\Sections\PaymentSection;
use Quittio\Core\View\Internal\Sections\ReferencesSection;
use Quittio\Core\View\Internal\Sections\TotalsSection;
use Quittio\Core\View\Internal\Sections\VatBreakdownSection;
use Quittio\Core\View\InvoiceViewModel;
use Quittio\Core\View\ViewContext;

/**
 * One build of ViewModelFactory. It creates the scope of the call (invoice, context, merged
 * presentation, labels) and lets one factory per section build its part of the view model, so
 * that the factory itself keeps no state.
 *
 * The result is the same nested array as before the split into sections; templates receive
 * it as typed, read-only nodes ({@see InvoiceViewModel}).
 *
 * @internal
 */
final class ViewModelRun
{
    public function build(InvoiceData $invoice, ViewContext $context = new ViewContext): InvoiceViewModel
    {
        if (! $invoice->isCalculated()) {
            throw new CalculationException('The invoice must be calculated before it can be rendered.');
        }

        $scope = RunScope::create($invoice, $context);
        [$data, $aliases] = $this->data($scope, new PaymentSection($scope));

        return new InvoiceViewModel($data, aliases: $aliases);
    }

    /**
     * View model for quotes, delivery notes, reminders and other documents.
     * Templates see the same structure as for invoices plus the "documentInfo" node.
     */
    public function buildDocument(DocumentData $document, ViewContext $context = new ViewContext): InvoiceViewModel
    {
        if (! $document->isCalculated()) {
            throw new CalculationException('The document must be calculated before it can be rendered.');
        }

        $scope = RunScope::create($document->toInvoiceDraft(billableOnly: false), $context, $document);
        $payment = new PaymentSection($scope);
        [$data, $aliases] = $this->data($scope, $payment);

        return new InvoiceViewModel((new DocumentSection($scope, $document, $payment))->apply($data), aliases: $aliases);
    }

    /**
     * The view model data, in the order templates have always seen it, and its aliases.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function data(RunScope $scope, PaymentSection $payment): array
    {
        $parties = new PartiesSection($scope);
        $partyData = $parties->build();
        // The design (letterhead, sender line, footer) shows the issuer; invalid colours, fonts
        // and layouts fail before the other sections are built.
        [$design, $footerAliases] = (new DesignSection($scope))->build($partyData['issuer']);
        $header = new HeaderSection($scope);

        $data = [
            ...$header->build(),
            'documentInfo' => null,
            ...$partyData,
            ...(new ReferencesSection($scope))->build(),
            ...(new LinesSection($scope))->build(),
            ...(new AllowancesSection($scope))->build(),
            ...(new VatBreakdownSection($scope))->build(),
            ...(new TotalsSection($scope))->build(),
            ...$payment->build(),
            ...(new NotesSection($scope))->build(),
            'labels' => $scope->labels,
            'design' => $design,
            'meta' => $header->meta(),
        ];

        // Issuer and recipient stand for seller and buyer; the generated footer prints the
        // issuer's address lines and tax identifiers.
        return [$data, [...$parties->aliases(), ...$footerAliases]];
    }
}
