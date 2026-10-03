<?php
/**
 * Default invoice template without Laravel (plain PHP).
 *
 * Variables: $invoice (InvoiceViewModel), $e (escape function).
 * Layout with tables, as recommended for mPDF. No calculation logic in the template.
 *
 * @var \Quittio\Core\View\InvoiceViewModel $invoice
 * @var callable(mixed): string $e
 */
$design = $invoice->design;
$labels = $invoice->labels;
$primary = $e($design->primaryColor);
$text = $e($design->textColor);
$muted = $e($design->mutedColor);
$doc = $invoice->document;
$info = $invoice->documentInfo;
$prices = (bool) $doc->showPrices;
$columns = $prices ? 6 : 3;
?>
<html>
<head>
<style>
    @page { margin: <?= $e($design->pageMarginTop) ?>mm 15mm 30mm 20mm; footer: html_quittioFooter; }
    body { font-family: <?= $e($design->font) ?>; font-size: 9pt; color: <?= $text ?>; line-height: 1.35; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }
    .muted { color: <?= $muted ?>; }
    .small { font-size: 7.5pt; }
    .right { text-align: right; }
    .letterhead td { height: <?= $e($design->letterheadHeight) ?>mm; }
    .company { font-size: 14pt; font-weight: bold; color: <?= $primary ?>; }
    /* mPDF ignores margin and padding of blocks inside table cells; spacing there uses cell padding. */
    .address { width: <?= $e($design->addressWidth) ?>mm; }
    .address td { padding: 0; }
    .address td.sender-line { font-size: 6.5pt; color: <?= $muted ?>; border-bottom: 0.2mm solid <?= $muted ?>; padding-bottom: 1mm; }
    .address td.address-lines { padding-top: 2mm; }
    .recipient { height: <?= $e($design->addressHeight) ?>mm; }
    .mark { position: absolute; left: 5mm; width: 5mm; height: 0; border-top: 0.2mm solid <?= $muted ?>; }
    .meta td { padding: 0.6mm 0; }
    .meta td.label { color: <?= $muted ?>; padding-right: 4mm; }
    h1 { font-size: 15pt; color: <?= $primary ?>; margin: 8mm 0 3mm 0; }
    .lines th { background-color: <?= $primary ?>; color: #ffffff; font-weight: bold; padding: 1.5mm 1.5mm; text-align: left; font-size: 8pt; }
    .lines td { padding: 1.5mm 1.5mm; border-bottom: 0.2mm solid #e5e7eb; }
    .lines .num { text-align: right; white-space: nowrap; }
    .lines .group td { font-weight: bold; background-color: #f3f4f6; }
    .lines .sub td { color: <?= $muted ?>; font-size: 8pt; }
    .description { color: <?= $muted ?>; font-size: 8pt; }
    .totals { width: 80mm; margin-left: auto; margin-top: 4mm; page-break-inside: avoid; }
    .totals td { padding: 1mm 1.5mm; }
    .totals .due td { font-weight: bold; font-size: 10.5pt; border-top: 0.4mm solid <?= $primary ?>; }
    .section { margin-top: 6mm; page-break-inside: avoid; }
    .section-title { font-weight: bold; color: <?= $primary ?>; margin-bottom: 1mm; }
    .stack td { padding: 0; }
    .stack td.gap { padding-top: 4mm; }
    .footer td { font-size: 7pt; color: <?= $muted ?>; padding-right: 3mm; }
</style>
</head>
<body>

<htmlpagefooter name="quittioFooter">
    <table class="footer"><tr>
        <?php foreach ($design->footerColumns as $column): ?>
            <td><?php foreach ($column as $line): ?><?= $e($line) ?><br><?php endforeach ?></td>
        <?php endforeach ?>
    </tr></table>
    <div class="small muted right"><?= $e($labels->page) ?> {PAGENO} <?= $e($labels->of) ?> {nbpg}</div>
</htmlpagefooter>

<?php if ($design->watermark): ?><watermarktext content="<?= $e($design->watermark) ?>" alpha="0.08" /><?php endif ?>
<?php foreach ($design->foldMarks as $mark): ?><div class="mark" style="top: <?= $e($mark) ?>mm;"></div><?php endforeach ?>
<?php if ($design->punchMark): ?><div class="mark" style="top: <?= $e($design->punchMark) ?>mm; width: 8mm;"></div><?php endif ?>

<table class="letterhead"><tr>
    <td>&nbsp;</td>
    <td class="right">
        <?php if ($design->logo): ?><img src="<?= $e($design->logo) ?>" style="max-height: 20mm; max-width: 60mm;"><br><?php endif ?>
        <span class="company"><?= $e($invoice->seller->displayName) ?></span>
    </td>
</tr></table>

<table><tr>
    <td style="width: 95mm;" class="recipient">
        <table class="address">
            <tr><td class="sender-line"><?= $e($design->senderLine) ?></td></tr>
            <tr><td class="address-lines"><?php foreach ($invoice->buyer->address->lines as $line): ?><?= $e($line) ?><br><?php endforeach ?></td></tr>
        </table>
    </td>
    <td>
        <table class="meta">
            <tr><td class="label"><?= $e($invoice->numberLabel) ?></td><td class="right"><?= $e($invoice->number) ?></td></tr>
            <tr><td class="label"><?= $e($invoice->dateLabel) ?></td><td class="right"><?= $e($invoice->issueDate) ?></td></tr>
            <?php if ($info && $info->validUntil): ?>
            <tr><td class="label"><?= $e($labels->valid_until) ?></td><td class="right"><?= $e($info->validUntil) ?></td></tr>
            <?php endif ?>
            <?php if ($info && $info->reference): ?>
            <tr><td class="label"><?= $e($labels->reference_document) ?></td><td class="right"><?= $e($info->reference) ?><?= $info->referenceDate ? ' / '.$e($info->referenceDate) : '' ?></td></tr>
            <?php endif ?>
            <?php if ($info && $info->deliveryDate): ?>
            <tr><td class="label"><?= $e($labels->delivery_date) ?></td><td class="right"><?= $e($info->deliveryDate) ?></td></tr>
            <?php endif ?>
            <?php if ($invoice->servicePeriod): ?>
            <tr><td class="label"><?= $e($labels->service_period) ?></td><td class="right"><?= $e($invoice->servicePeriod) ?></td></tr>
            <?php endif ?>
            <?php if ($invoice->buyer->customerNumber): ?>
            <tr><td class="label"><?= $e($labels->customer_number) ?></td><td class="right"><?= $e($invoice->buyer->customerNumber) ?></td></tr>
            <?php endif ?>
            <?php foreach ($invoice->references as $reference): ?>
            <tr><td class="label"><?= $e($reference->label) ?></td><td class="right"><?= $e($reference->value) ?></td></tr>
            <?php endforeach ?>
            <?php if ($invoice->seller->vatId): ?>
            <tr><td class="label"><?= $e($labels->vat_id) ?></td><td class="right"><?= $e($invoice->seller->vatId) ?></td></tr>
            <?php endif ?>
            <?php if ($invoice->seller->taxNumber): ?>
            <tr><td class="label"><?= $e($labels->tax_number) ?></td><td class="right"><?= $e($invoice->seller->taxNumber) ?></td></tr>
            <?php endif ?>
            <?php if ($invoice->buyer->vatId): ?>
            <tr><td class="label"><?= $e($labels->buyer) ?> <?= $e($labels->vat_id) ?></td><td class="right"><?= $e($invoice->buyer->vatId) ?></td></tr>
            <?php endif ?>
        </table>
    </td>
</tr></table>

<h1><?= $e($invoice->title) ?></h1>

<?php foreach ($invoice->precedingInvoices as $preceding): ?>
    <p><?= $e(str_replace([':number', ':date'], [(string) $preceding->number, (string) $preceding->issueDate], (string) $labels->preceding_invoice_text)) ?></p>
<?php endforeach ?>

<?php if ($invoice->texts->salutation): ?><p><?= $e($invoice->texts->salutation) ?></p><?php endif ?>
<?php if ($invoice->texts->intro): ?><div><?= $invoice->texts->intro ?></div><?php endif ?>

<?php if (count($invoice->lines) > 0): ?>
<table class="lines">
    <thead><tr>
        <th style="width: 10mm;"><?= $e($labels->position) ?></th>
        <th><?= $e($labels->description) ?></th>
        <th class="num"><?= $e($labels->quantity) ?></th>
        <?php if ($prices): ?>
        <th class="num"><?= $e($labels->unit_price) ?></th>
        <th class="num"><?= $e($labels->vat_rate) ?></th>
        <th class="num"><?= $e($labels->total) ?></th>
        <?php endif ?>
    </tr></thead>
    <tbody>
    <?php foreach ($invoice->lineGroups as $group): ?>
        <?php if ($group->title): ?><tr class="group"><td colspan="<?= $columns ?>"><?= $e($group->title) ?></td></tr><?php endif ?>
        <?php foreach ($group->lines as $line): ?>
            <tr>
                <td><?= $e($line->position) ?></td>
                <td>
                    <?php if ($line->optional): ?><span class="muted">[<?= $e($labels->optional_line) ?>]</span> <?php endif ?>
                    <?php if ($line->alternativeTo): ?><span class="muted">[<?= $e($invoice->label('alternative_line', ['position' => (string) $line->alternativeTo])) ?>]</span> <?php endif ?>
                    <strong><?= $e($line->name) ?></strong>
                    <?php if ($design->showItemNumbers && $line->sellerId): ?><span class="muted"> · <?= $e($labels->item_number) ?> <?= $e($line->sellerId) ?></span><?php endif ?>
                    <?php if ($line->description->html): ?><div class="description"><?= $line->description->html ?></div><?php endif ?>
                    <?php if ($line->period): ?><div class="description"><?= $e($labels->service_period) ?>: <?= $e($line->period) ?></div><?php endif ?>
                    <?php foreach ($line->attributes as $attribute): ?><div class="description"><?= $e($attribute->name) ?>: <?= $e($attribute->value) ?></div><?php endforeach ?>
                    <?php foreach ($line->allowances as $allowance): ?><div class="description"><?= $e($labels->allowance) ?> <?= $e($allowance->reason) ?> <?= $e($allowance->percent) ?>: −<?= $e($allowance->amount) ?></div><?php endforeach ?>
                    <?php foreach ($line->charges as $charge): ?><div class="description"><?= $e($labels->charge) ?> <?= $e($charge->reason) ?> <?= $e($charge->percent) ?>: <?= $e($charge->amount) ?></div><?php endforeach ?>
                    <?php if ($line->note): ?><div class="description"><?= $e($line->note) ?></div><?php endif ?>
                    <?php if (count($line->serialNumbers) > 0): ?><div class="description"><?= $e($labels->serial_numbers) ?>: <?php foreach ($line->serialNumbers as $i => $serial): ?><?= $i > 0 ? ', ' : '' ?><?= $e($serial) ?><?php endforeach ?></div><?php endif ?>
                </td>
                <td class="num"><?= $e($line->quantity) ?> <?= $e($line->unit->label) ?></td>
                <?php if ($prices): ?>
                <td class="num"><?= $e($line->showGrossPrice ? $line->grossUnitPrice : $line->unitPrice) ?></td>
                <td class="num"><?= $e($line->vatRate) ?></td>
                <td class="num"><?= $e($line->total) ?></td>
                <?php endif ?>
            </tr>
            <?php foreach ($line->subLines as $sub): ?>
                <tr class="sub">
                    <td><?= $e($sub->position) ?></td>
                    <td><?= $e($sub->name) ?></td>
                    <td class="num"><?= $e($sub->quantity) ?> <?= $e($sub->unit->label) ?></td>
                    <?php if ($prices): ?>
                    <td class="num"><?= $e($sub->unitPrice) ?></td>
                    <td class="num"><?= $e($sub->vatRate) ?></td>
                    <td class="num"><?= $e($sub->total) ?></td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
        <?php endforeach ?>
        <?php if ($prices && $group->subtotal): ?><tr><td></td><td colspan="4" class="right muted"><?= $e($labels->subtotal) ?></td><td class="num"><?= $e($group->subtotal) ?></td></tr><?php endif ?>
    <?php endforeach ?>
    </tbody>
</table>
<?php endif ?>

<?php if ($invoice->totals && $doc->showTotals): ?>
<table class="totals">
    <?php if ($invoice->totals->allowanceSum || $invoice->totals->chargeSum): ?>
        <tr><td><?= $e($labels->line_net_sum) ?></td><td class="right"><?= $e($invoice->totals->lineNetSum) ?></td></tr>
        <?php foreach ($invoice->allowances as $allowance): ?>
            <tr><td><?= $e($labels->allowance) ?> <?= $e($allowance->reason) ?> <?= $e($allowance->percent) ?></td><td class="right">−<?= $e($allowance->amount) ?></td></tr>
        <?php endforeach ?>
        <?php foreach ($invoice->charges as $charge): ?>
            <tr><td><?= $e($labels->charge) ?> <?= $e($charge->reason) ?> <?= $e($charge->percent) ?></td><td class="right"><?= $e($charge->amount) ?></td></tr>
        <?php endforeach ?>
    <?php endif ?>
    <tr><td><?= $e($labels->net_total) ?></td><td class="right"><?= $e($invoice->totals->netTotal) ?></td></tr>
    <?php foreach ($invoice->vatRows as $row): ?>
        <tr><td><?= $e($row->label) ?></td><td class="right"><?= $e($row->amount) ?></td></tr>
    <?php endforeach ?>
    <tr><td><strong><?= $e($labels->gross_total) ?></strong></td><td class="right"><strong><?= $e($invoice->totals->grossTotal) ?></strong></td></tr>
    <?php if ($invoice->totals->prepaid): ?><tr><td><?= $e($labels->prepaid) ?></td><td class="right">−<?= $e($invoice->totals->prepaid) ?></td></tr><?php endif ?>
    <?php if ($invoice->totals->rounding): ?><tr><td><?= $e($labels->rounding) ?></td><td class="right"><?= $e($invoice->totals->rounding) ?></td></tr><?php endif ?>
    <?php foreach ($invoice->totals->thirdPartyPayments as $payment): ?><tr><td><?= $e($payment->description) ?></td><td class="right"><?= $e($payment->amount) ?></td></tr><?php endforeach ?>
    <tr class="due"><td><?= $e($labels->amount_due) ?></td><td class="right"><?= $e($invoice->totals->amountDue) ?></td></tr>
</table>
<?php if ($invoice->totals->taxCurrencyNote): ?><p class="small muted right"><?= $e($invoice->totals->taxCurrencyNote) ?></p><?php endif ?>
<?php endif ?>

<?php if ($info && count($info->packages) > 0): ?>
<div class="section">
    <div class="section-title"><?= $e($labels->packages) ?></div>
    <?php foreach ($info->packages as $package): ?>
        <div><?= $e($package->id) ?><?= $package->type ? ' · '.$e($package->type) : '' ?><?= $package->weight ? ' · '.$e($labels->weight).' '.$e($package->weight) : '' ?><?= $package->dimensions ? ' · '.$e($package->dimensions) : '' ?><?= $package->trackingNumber ? ' · '.$e($labels->tracking_number).' '.$e($package->trackingNumber) : '' ?></div>
    <?php endforeach ?>
</div>
<?php endif ?>

<?php foreach (array_filter([$info?->dunning, $info?->statement]) as $ledger): ?>
<table class="lines" style="margin-top: 4mm;">
    <thead><tr>
        <th><?= $e($labels->invoice_number) ?></th>
        <th><?= $e($labels->invoice_date) ?></th>
        <th><?= $e($labels->due_date) ?></th>
        <th class="num"><?= $e($labels->invoice_amount) ?></th>
        <th class="num"><?= $e($labels->paid) ?></th>
        <th class="num"><?= $e($labels->open_amount) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach (($ledger->openItems ?? $ledger->items) as $item): ?>
        <tr>
            <td><?= $e($item->invoiceNumber) ?><?php if ($item->description): ?><div class="description"><?= $e($item->description) ?></div><?php endif ?></td>
            <td><?= $e($item->invoiceDate) ?></td>
            <td><?= $e($item->dueDate) ?></td>
            <td class="num"><?= $e($item->amount) ?></td>
            <td class="num"><?= $e($item->paid) ?></td>
            <td class="num"><?= $e($item->open) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
<?php endforeach ?>

<?php if ($info && $info->dunning): ?>
<table class="totals">
    <tr><td><?= $e($labels->open_items) ?></td><td class="right"><?= $e($info->dunning->openTotal) ?></td></tr>
    <?php foreach ($info->dunning->fees as $fee): ?><tr><td><?= $e($fee->description) ?></td><td class="right"><?= $e($fee->amount) ?></td></tr><?php endforeach ?>
    <?php foreach ($info->dunning->interest as $interest): ?><tr><td><?= $e($interest->description) ?></td><td class="right"><?= $e($interest->amount) ?></td></tr><?php endforeach ?>
    <tr class="due"><td><?= $e($labels->dunning_total) ?></td><td class="right"><?= $e($info->dunning->total) ?></td></tr>
</table>
<?php if ($info->dunning->deadlineText): ?><p><?= $e($info->dunning->deadlineText) ?></p><?php endif ?>
<?php endif ?>

<?php if ($info && $info->statement): ?>
<table class="totals">
    <tr><td><?= $e($labels->statement_period) ?></td><td class="right"><?= $e($info->statement->period) ?></td></tr>
    <?php if ($info->statement->openingBalance): ?><tr><td><?= $e($labels->opening_balance) ?></td><td class="right"><?= $e($info->statement->openingBalance) ?></td></tr><?php endif ?>
    <tr class="due"><td><?= $e($labels->balance) ?></td><td class="right"><?= $e($info->statement->balance) ?></td></tr>
</table>
<?php endif ?>

<?php if (count($invoice->legalNotes) > 0): ?>
<div class="section">
    <?php foreach ($invoice->legalNotes as $note): ?><div><?= $e($note) ?></div><?php endforeach ?>
</div>
<?php endif ?>

<?php if ($invoice->paymentTerms || $invoice->payment): ?>
<div class="section">
    <table><tr>
        <td>
            <table class="stack">
            <?php $gap = ''; ?>
            <?php if ($invoice->paymentTerms): ?>
                <tr><td class="<?= $gap ?>">
                    <div class="section-title"><?= $e($labels->payment_terms) ?></div>
                    <?php foreach ($invoice->paymentTerms->lines as $line): ?><div><?= $e($line) ?></div><?php endforeach ?>
                </td></tr>
                <?php $gap = 'gap'; ?>
            <?php endif ?>
            <?php if ($invoice->payment): ?>
                <?php foreach ($invoice->payment->accounts as $account): ?>
                    <tr><td class="<?= $gap ?>">
                        <div class="section-title"><?= $e($labels->bank_details) ?></div>
                        <?php if ($account->name): ?><?= $e($labels->account_holder) ?>: <?= $e($account->name) ?><br><?php endif ?>
                        <?= $e($labels->iban) ?>: <?= $e($account->iban) ?><br>
                        <?php if ($account->bic): ?><?= $e($labels->bic) ?>: <?= $e($account->bic) ?><br><?php endif ?>
                        <?= $e($labels->remittance) ?>: <?= $e($invoice->payment->remittance) ?>
                    </td></tr>
                    <?php $gap = 'gap'; ?>
                <?php endforeach ?>
                <?php if ($invoice->payment->directDebit): ?>
                    <tr><td class="<?= $gap ?>"><?= $e($labels->direct_debit) ?>
                        <?php if ($invoice->payment->directDebit->mandate): ?><?= $e($labels->mandate) ?>: <?= $e($invoice->payment->directDebit->mandate) ?><?php endif ?>
                        <?php if ($invoice->payment->directDebit->creditorId): ?> · <?= $e($labels->creditor_id) ?>: <?= $e($invoice->payment->directDebit->creditorId) ?><?php endif ?>
                    </td></tr>
                <?php endif ?>
            <?php endif ?>
            </table>
        </td>
        <?php if ($invoice->payment && $invoice->payment->qrCode): ?>
            <td style="width: 32mm;" class="right">
                <img src="<?= $e($invoice->payment->qrCode->dataUri) ?>" style="width: 28mm; height: 28mm;"><br>
                <span class="small muted"><?= $e($labels->girocode) ?></span>
            </td>
        <?php endif ?>
    </tr></table>
</div>
<?php endif ?>

<?php if (count($invoice->notes) > 0): ?>
<div class="section">
    <div class="section-title"><?= $e($labels->notes) ?></div>
    <?php foreach ($invoice->notes as $note): ?><div><?= $note->html ?></div><?php endforeach ?>
</div>
<?php endif ?>

<?php if (count($invoice->attachments) > 0): ?>
<div class="section">
    <div class="section-title"><?= $e($labels->attachments) ?></div>
    <?php foreach ($invoice->attachments as $attachment): ?><div><?= $e($attachment->id) ?><?= $attachment->description ? ' – '.$e($attachment->description) : '' ?></div><?php endforeach ?>
</div>
<?php endif ?>

<?php if ($invoice->texts->closing): ?><div class="section"><?= $invoice->texts->closing ?></div><?php endif ?>

<?php if ($info && $info->showAcceptance): ?>
<div class="section">
    <div class="section-title"><?= $e($labels->acceptance) ?></div>
    <table style="margin-top: 12mm; width: 80mm;"><tr><td style="border-top: 0.2mm solid <?= $muted ?>;" class="small muted"><?= $e($labels->acceptance_signature) ?></td></tr></table>
</div>
<?php endif ?>

</body>
</html>
