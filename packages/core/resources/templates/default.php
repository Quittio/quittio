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
$secondary = $e($design->secondaryColor);
$text = $e($design->textColor);
$muted = $e($design->mutedColor);
// mPDF knows only normal and bold: medium and semibold come from a family of their own (Geist).
$medium = $design->fontMedium ? 'font-family: '.$e($design->fontMedium).';' : 'font-weight: bold;';
$semibold = $design->fontMedium ? 'font-family: '.$e($design->fontMedium).'; font-weight: bold;' : 'font-weight: bold;';
$doc = $invoice->document;
$info = $invoice->documentInfo;
$selfBilled = (bool) $doc->isSelfBilled;
$prices = (bool) $doc->showPrices;
// A VAT column only for several rates; one rate is named in the totals.
$vatColumn = $prices && count($invoice->vatRows) > 1;
$columns = $prices ? ($vatColumn ? 6 : 5) : 3;
$descriptionWidth = $prices ? ($vatColumn ? 65.9 : 79.9) : 135.9;
$brand = function () use ($design, $invoice, $e): string {
    return $design->logo
        ? '<img src="'.$e($design->logo).'" style="max-height: '.$e($design->logoMaxHeight).'mm; max-width: 60mm;">'
        : '<div class="company">'.$e($invoice->issuer->displayName).'</div>';
};
?>
<html>
<head>
<style>
    /* Following pages start below their header (logo, title, number and buyer); the first page with the letterhead. */
    @page { margin: <?= $e($design->continuationMarginTop) ?>mm 19mm 37mm 20mm; margin-header: 14.6mm; margin-footer: 5.5mm; header: html_quittioHeader; footer: html_quittioFooter; }
    @page :first { margin-top: <?= $e($design->pageMarginTop) ?>mm; header: html_quittioFirstHeader; }
    body { font-family: <?= $e($design->font) ?>; font-size: 9.75pt; color: <?= $text ?>; line-height: 1.45; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; padding: 0; line-height: 1.5; }
    .muted { color: <?= $muted ?>; }
    .small { font-size: 7.9pt; }
    .right { text-align: right; }
    .letterhead td { height: <?= $e($design->letterheadHeight) ?>mm; }
    .company { font-size: 16.5pt; font-weight: bold; color: <?= $primary ?>; line-height: 1.2; }
    .issuer-address { font-size: 7.9pt; color: <?= $muted ?>; }
    /* mPDF ignores margin and padding of blocks inside table cells; spacing there uses cell padding. */
    /* The address field is positioned on the page (letter layout, e.g. DIN 5008), whatever the letterhead height. */
    .address { position: absolute; top: <?= $e($design->addressTop) ?>mm; left: <?= $e($design->addressLeft) ?>mm; width: <?= $e($design->addressWidth) ?>mm; height: <?= $e($design->addressHeight) ?>mm; overflow: visible; }
    .sender-line { font-size: 9pt; color: <?= $muted ?>; border-bottom: 0.26mm solid #cdd0d6; padding-bottom: 1.2mm; line-height: 1.3; }
    .address-lines { font-size: 10.5pt; line-height: 1.5; padding-top: 2.2mm; }
    .mark { position: absolute; left: 0; width: 4.2mm; height: 0; border-top: 0.26mm solid #cdd0d6; }
    .head td.address-space { width: 108.6mm; height: 41.8mm; }
    .meta td { font-size: 9.4pt; padding-bottom: 0.75mm; line-height: 1.5; }
    .meta td.label { color: <?= $muted ?>; padding-right: 4mm; }
    .meta td.value { text-align: right; }
    h1 { font-size: 13.5pt; color: <?= $primary ?>; font-weight: normal; <?= $semibold ?> margin: 0 0 5mm 0; line-height: 1.5; }
    .lines th { font-size: 9pt; color: <?= $muted ?>; font-weight: normal; <?= $medium ?> text-align: left; padding: 0 0 2mm 0; border-bottom: 0.26mm solid <?= $text ?>; }
    .lines td { padding: 2.2mm 0 2.6mm 0; border-bottom: 0.26mm solid #e6e8ec; }
    .lines .num { text-align: right; white-space: nowrap; }
    .lines td.num, .lines td.position { color: <?= $muted ?>; }
    .lines td.amount { color: <?= $text ?>; }
    .lines .name { <?= $medium ?> }
    .lines .group td { <?= $semibold ?> padding-top: 5mm; }
    .lines .sub td { color: <?= $muted ?>; font-size: 9pt; padding-top: 1mm; }
    .lines .subtotal td { color: <?= $muted ?>; }
    /* Highlighted lines (LineBuilder::highlight()): shaded, marked in the secondary colour. */
    .lines .highlight td { background-color: #f8fafc; }
    .lines .highlight td.position { border-left: 0.8mm solid <?= $secondary ?>; padding-left: 1.5mm; }
    /* Carry-over rows at page breaks (filled in by the PDF renderer). */
    .lines .carry td { color: <?= $muted ?>; border-bottom: 0; padding-top: 3.6mm; }
    .lines .carry-in td { border-bottom: 0.26mm solid #e6e8ec; padding-top: 2.2mm; }
    .lines td.carry-amount { color: <?= $text ?>; <?= $medium ?> }
    .description { color: <?= $muted ?>; font-size: 9pt; }
    .totals { width: 74.2mm; margin-left: auto; margin-top: 3.1mm; page-break-inside: avoid; }
    .totals td { padding: 0.47mm 0; }
    .totals tr.gap td { height: 2.2mm; padding: 0; line-height: 1; }
    .totals td.label { color: <?= $muted ?>; }
    .totals .gross td.label { color: <?= $text ?>; }
    .totals .grand td { border-top: 0.26mm solid <?= $secondary ?>; padding-top: 3.1mm; vertical-align: bottom; line-height: 1.2; }
    .totals .grand td.label { font-size: 10.5pt; color: <?= $text ?>; <?= $semibold ?> }
    .totals .grand td.amount { font-size: 12.75pt; font-weight: bold; color: <?= $primary ?>; }
    .section { margin-top: 8.3mm; page-break-inside: avoid; }
    .section-title { font-size: 9pt; color: <?= $secondary ?>; <?= $semibold ?> }
    .payment td { font-size: 9.4pt; }
    .payment td.terms { width: 77mm; padding-right: 6mm; }
    td.qr { width: 17mm; text-align: center; padding-left: 4mm; }
    .payment td.section-title { font-size: 9pt; padding-bottom: 0.7mm; }
    .qr-caption { font-size: 7.9pt; color: <?= $muted ?>; text-align: center; }
    .running td { vertical-align: top; }
    .running-title { font-size: 11.25pt; font-weight: bold; color: <?= $primary ?>; }
    .running-meta { font-size: 9pt; color: <?= $muted ?>; }
    .footer-rule { border-top: 0.26mm solid #e6e8ec; }
    .footer td { font-size: 7.9pt; color: <?= $muted ?>; padding: 3.4mm 3mm 0 0; }
    .page-number { font-size: 7.9pt; color: <?= $muted ?>; text-align: center; padding-top: 6.9mm; }
    <?php if ($design->watermarkCss): ?>
    /* Watermark for browsers; mPDF ignores generated content and prints <watermarktext> instead. */
    body::before { content: <?= $design->watermarkCss ?>; position: fixed; top: 45%; left: 0; right: 0; text-align: center; font-size: 72pt; font-weight: bold; color: rgba(0, 0, 0, 0.08); transform: rotate(-35deg); pointer-events: none; z-index: 1000; }
    <?php endif ?>
</style>
</head>
<body>

<?php /* Header of the following pages: logo or name, title with number, recipient and date. The first page has the letterhead. */ ?>
<htmlpageheader name="quittioFirstHeader"></htmlpageheader>
<htmlpageheader name="quittioHeader">
    <table class="running"><tr>
        <td><?= $brand() ?></td>
        <td class="right">
            <div class="running-title"><?= $e($invoice->title) ?> <?= $e($invoice->number) ?></div>
            <div class="running-meta"><?= $e($invoice->recipient->displayName) ?><?php if (! $selfBilled && $invoice->buyer->customerNumber): ?> · <?= $e($labels->customer_number) ?> <?= $e($invoice->buyer->customerNumber) ?><?php endif ?></div>
            <div class="running-meta"><?= $e($invoice->dateLabel) ?> <?= $e($invoice->issueDate) ?></div>
        </td>
    </tr></table>
</htmlpageheader>

<htmlpagefooter name="quittioFooter">
    <?php if (count($design->footerColumns) > 0): ?>
    <div class="footer-rule"><table class="footer"><tr>
        <?php if (count($design->footerColumns) <= 4): ?>
            <?php /* Up to four columns at a fixed pitch of 43.8 mm. */ ?>
            <?php foreach ([43.8, 43.8, 43.8, 39.6] as $i => $width): ?>
                <td style="width: <?= $width ?>mm;"><?php if (isset($design->footerColumns[$i])): ?><?php foreach ($design->footerColumns[$i] as $line): ?><?= $e($line) ?><br><?php endforeach ?><?php endif ?></td>
            <?php endforeach ?>
        <?php else: ?>
            <?php foreach ($design->footerColumns as $column): ?>
                <td><?php foreach ($column as $line): ?><?= $e($line) ?><br><?php endforeach ?></td>
            <?php endforeach ?>
        <?php endif ?>
    </tr></table></div>
    <?php endif ?>
    <div class="page-number"><?= $e($labels->page) ?> {PAGENO} <?= $e($labels->of) ?> {nbpg}</div>
</htmlpagefooter>

<?php if ($design->watermark): ?><watermarktext content="<?= $e($design->watermark) ?>" alpha="0.08" /><?php endif ?>
<?php /* Fold and punch marks of the letter layout (e.g. DIN 5008) at the left edge (option fold_marks). */ ?>
<?php foreach ($design->foldMarks as $mark): ?><div class="mark" style="top: <?= $e($mark) ?>mm;"></div><?php endforeach ?>
<?php if ($design->punchMark): ?><div class="mark" style="top: <?= $e($design->punchMark) ?>mm; width: 6.3mm;"></div><?php endif ?>

<?php /* The recipient is the buyer; on a self-billed invoice (Gutschrift) the buyer issues it to the seller. */ ?>
<div class="address">
    <div class="sender-line"><?= $e($design->senderLine) ?></div>
    <div class="address-lines"><?php foreach ($invoice->recipient->address->lines as $line): ?><?= $e($line) ?><br><?php endforeach ?></div>
</div>

<?php /* Logo, or the issuer's name, at the top left of the first page. */ ?>
<table class="letterhead"><tr>
    <td>
        <?= $brand() ?>
        <?php if (! $design->footerHasAddress): ?><div class="issuer-address"><?php foreach ($invoice->issuer->address->lines as $line): ?><?= $e($line) ?><br><?php endforeach ?></div><?php endif ?>
    </td>
</tr></table>

<table class="head"><tr>
    <td class="address-space">&nbsp;</td>
    <td class="meta-cell">
        <table class="meta">
            <tr><td class="label"><?= $e($invoice->numberLabel) ?></td><td class="value"><?= $e($invoice->number) ?></td></tr>
            <tr><td class="label"><?= $e($invoice->dateLabel) ?></td><td class="value"><?= $e($invoice->issueDate) ?></td></tr>
            <?php if ($info && $info->validUntil): ?>
            <tr><td class="label"><?= $e($labels->valid_until) ?></td><td class="value"><?= $e($info->validUntil) ?></td></tr>
            <?php endif ?>
            <?php if ($info && $info->reference): ?>
            <tr><td class="label"><?= $e($labels->reference_document) ?></td><td class="value"><?= $e($info->reference) ?><?= $info->referenceDate ? ' / '.$e($info->referenceDate) : '' ?></td></tr>
            <?php endif ?>
            <?php if ($info && $info->deliveryDate): ?>
            <tr><td class="label"><?= $e($labels->delivery_date) ?></td><td class="value"><?= $e($info->deliveryDate) ?></td></tr>
            <?php endif ?>
            <?php if ($invoice->servicePeriod): ?>
            <tr><td class="label"><?= $e($invoice->servicePeriodLabel) ?></td><td class="value"><?= $e($invoice->servicePeriod) ?></td></tr>
            <?php endif ?>
            <?php if ($invoice->deliveryDate): ?>
            <tr><td class="label"><?= $e($labels->delivery_date) ?></td><td class="value"><?= $e($invoice->deliveryDate) ?></td></tr>
            <?php endif ?>
            <?php if ($invoice->buyer->customerNumber): ?>
            <tr><td class="label"><?= $e($labels->customer_number) ?></td><td class="value"><?= $e($invoice->buyer->customerNumber) ?></td></tr>
            <?php endif ?>
            <?php foreach ($invoice->references as $reference): ?>
            <tr><td class="label"><?= $e($reference->label) ?></td><td class="value"><?= $e($reference->value) ?></td></tr>
            <?php endforeach ?>
            <?php /* The issuer's tax identifiers are in the footer; here only if the footer does not show them. */ ?>
            <?php if (! $design->footerHasTaxIds): ?>
                <?php if ($invoice->issuer->vatId): ?>
                <tr><td class="label"><?= $e($labels->vat_id) ?></td><td class="value"><?= $e($invoice->issuer->vatId) ?></td></tr>
                <?php endif ?>
                <?php if (! $selfBilled && $invoice->seller->taxNumber): ?>
                <tr><td class="label"><?= $e($labels->tax_number) ?></td><td class="value"><?= $e($invoice->seller->taxNumber) ?></td></tr>
                <?php endif ?>
            <?php endif ?>
            <?php /* The recipient's identifiers; on a self-billed invoice (Gutschrift) those of the seller. */ ?>
            <?php if ($invoice->recipient->vatId): ?>
            <tr><td class="label"><?= $e($labels->your_vat_id) ?></td><td class="value"><?= $e($invoice->recipient->vatId) ?></td></tr>
            <?php endif ?>
            <?php if ($selfBilled && $invoice->seller->taxNumber): ?>
            <tr><td class="label"><?= $e($labels->your_tax_number) ?></td><td class="value"><?= $e($invoice->seller->taxNumber) ?></td></tr>
            <?php endif ?>
            <?php if ($invoice->taxRepresentative): ?>
            <tr><td class="label"><?= $e($labels->tax_representative) ?></td><td class="value"><?php foreach ($invoice->taxRepresentative->address->lines as $line): ?><?= $e($line) ?><br><?php endforeach ?><?= $e($labels->vat_id) ?> <?= $e($invoice->taxRepresentative->vatId) ?></td></tr>
            <?php endif ?>
            <?php if ($invoice->delivery && $invoice->delivery->address): ?>
            <tr><td class="label"><?= $e($labels->deliver_to) ?></td><td class="value"><?php foreach ($invoice->delivery->address->lines as $line): ?><?= $e($line) ?><br><?php endforeach ?></td></tr>
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
<?php /* Fixed column widths: the PDF renderer splits the table at page breaks and inserts the carry-over. */ ?>
<table class="lines">
    <thead><tr>
        <th style="width: 11.1mm;"><?= $e($labels->position) ?></th>
        <th style="width: <?= $descriptionWidth ?>mm;"><?= $e($labels->description) ?></th>
        <th class="num" style="width: 24mm;"><?= $e($labels->quantity) ?></th>
        <?php if ($prices): ?>
        <th class="num" style="width: 27mm;"><?= $e($labels->unit_price) ?></th>
        <?php if ($vatColumn): ?><th class="num" style="width: 14mm;"><?= $e($labels->vat_rate) ?></th><?php endif ?>
        <th class="num" style="width: 29mm;"><?= $e($labels->total) ?></th>
        <?php endif ?>
    </tr></thead>
    <tbody>
    <!--quittio:rows-->
    <?php foreach ($invoice->lineGroups as $group): ?>
        <?php if ($group->title): ?><tr class="group"><td colspan="<?= $columns ?>"><?= $e($group->title) ?></td></tr><?php endif ?>
        <?php foreach ($group->lines as $line): ?>
            <tr class="line<?= $line->highlight ? ' highlight' : '' ?>"<?php if ($prices): ?> data-carry-over="<?= $e($line->carryOver) ?>"<?php endif ?>>
                <td class="position"><?= $e($line->position) ?></td>
                <td>
                    <div class="name"><?php if ($line->optional): ?><span class="muted">[<?= $e($labels->optional_line) ?>]</span> <?php endif ?><?php if ($line->alternativeTo): ?><span class="muted">[<?= $e($invoice->label('alternative_line', ['position' => (string) $line->alternativeTo])) ?>]</span> <?php endif ?><?= $e($line->name) ?><?php if ($design->showItemNumbers && $line->sellerId): ?><span class="muted"> · <?= $e($labels->item_number) ?> <?= $e($line->sellerId) ?></span><?php endif ?></div>
                    <?php if ($line->description->html): ?><div class="description"><?= $line->description->html ?></div><?php endif ?>
                    <?php if ($line->period): ?><div class="description"><?= $e($labels->service_period) ?> <?= $e($line->period) ?></div><?php endif ?>
                    <?php foreach ($line->attributes as $attribute): ?><div class="description"><?= $e($attribute->name) ?>: <?= $e($attribute->value) ?></div><?php endforeach ?>
                    <?php foreach ($line->allowances as $allowance): ?><div class="description"><?= $e($allowance->label) ?>: −<?= $e($allowance->amount) ?></div><?php endforeach ?>
                    <?php foreach ($line->charges as $charge): ?><div class="description"><?= $e($charge->label) ?>: <?= $e($charge->amount) ?></div><?php endforeach ?>
                    <?php if ($line->note): ?><div class="description"><?= $e($line->note) ?></div><?php endif ?>
                    <?php if (count($line->serialNumbers) > 0): ?><div class="description"><?= $e($labels->serial_numbers) ?>: <?php foreach ($line->serialNumbers as $i => $serial): ?><?= $i > 0 ? ', ' : '' ?><?= $e($serial) ?><?php endforeach ?></div><?php endif ?>
                </td>
                <td class="num"><?= $e($line->quantity) ?> <?= $e($line->unit->label) ?></td>
                <?php if ($prices): ?>
                <td class="num"><?= $e($line->unitPrice) ?><?php if ($line->unitPriceBase): ?><div class="description">/ <?= $e($line->unitPriceBase) ?></div><?php endif ?><?php if ($line->showGrossPrice): ?><div class="description"><?= $e($labels->unit_price_gross) ?> <?= $e($line->grossUnitPrice) ?></div><?php endif ?></td>
                <?php if ($vatColumn): ?><td class="num"><?= $e($line->vatRate) ?></td><?php endif ?>
                <td class="num amount"><?= $e($line->total) ?></td>
                <?php endif ?>
            </tr>
            <?php foreach ($line->subLines as $sub): ?>
                <tr class="sub">
                    <td><?= $e($sub->position) ?></td>
                    <td><?= $e($sub->name) ?></td>
                    <td class="num"><?= $e($sub->quantity) ?> <?= $e($sub->unit->label) ?></td>
                    <?php if ($prices): ?>
                    <td class="num"><?= $e($sub->unitPrice) ?><?php if ($sub->unitPriceBase): ?><div class="description">/ <?= $e($sub->unitPriceBase) ?></div><?php endif ?></td>
                    <?php if ($vatColumn): ?><td class="num"><?= $e($sub->vatRate) ?></td><?php endif ?>
                    <td class="num"><?= $e($sub->total) ?></td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
        <?php endforeach ?>
        <?php if ($prices && $group->subtotal): ?><tr class="subtotal"><td></td><td colspan="<?= $columns - 2 ?>" class="right"><?= $e($labels->subtotal) ?></td><td class="num amount"><?= $e($group->subtotal) ?></td></tr><?php endif ?>
    <?php endforeach ?>
    <!--/quittio:rows-->
    </tbody>
</table>
<?php if ($prices): ?>
<?php /* Rows the PDF renderer inserts where the table continues on the next page; {page} and {amount} are filled in. */ ?>
<!--quittio:carry-out <tr class="carry carry-out"><td colspan="2"><?= $e($invoice->label('continued_on', ['page' => '{page}'])) ?></td><td colspan="<?= $columns - 3 ?>" style="padding-left: 5.8mm;"><?= $e($labels->carry_over) ?></td><td class="num carry-amount">{amount}</td></tr> -->
<!--quittio:carry-in <tr class="carry carry-in"><td></td><td colspan="<?= $columns - 2 ?>"><?= $e($invoice->label('carry_over_from', ['page' => '{page}'])) ?></td><td class="num">{amount}</td></tr> -->
<?php endif ?>
<?php endif ?>

<?php if ($invoice->totals && $doc->showTotals): ?>
<?php $totals = $invoice->totals; ?>
<table class="totals">
    <?php if ($totals->allowanceSum || $totals->chargeSum): ?>
        <tr><td class="label"><?= $e($labels->line_net_sum) ?></td><td class="right"><?= $e($totals->lineNetSum) ?></td></tr>
        <?php foreach ($invoice->allowances as $allowance): ?>
            <tr><td class="label"><?= $e($allowance->label) ?></td><td class="right">−<?= $e($allowance->amount) ?></td></tr>
        <?php endforeach ?>
        <?php foreach ($invoice->charges as $charge): ?>
            <tr><td class="label"><?= $e($charge->label) ?></td><td class="right"><?= $e($charge->amount) ?></td></tr>
        <?php endforeach ?>
    <?php endif ?>
    <tr><td class="label"><?= $e($labels->net_total) ?></td><td class="right"><?= $e($totals->netTotal) ?></td></tr>
    <?php foreach ($invoice->vatRows as $row): ?>
        <tr><td class="label"><?= $e($row->label) ?></td><td class="right"><?= $e($row->amount) ?></td></tr>
    <?php endforeach ?>
    <?php if ($totals->amountDue && ! $totals->dueEqualsGross): ?>
        <tr class="gross"><td class="label"><?= $e($labels->gross_total) ?></td><td class="right"><?= $e($totals->grossTotal) ?></td></tr>
        <?php if ($totals->prepaid): ?><tr><td class="label"><?= $e($labels->prepaid) ?></td><td class="right">−<?= $e($totals->prepaid) ?></td></tr><?php endif ?>
        <?php if ($totals->rounding): ?><tr><td class="label"><?= $e($labels->rounding) ?></td><td class="right"><?= $e($totals->rounding) ?></td></tr><?php endif ?>
        <?php foreach ($totals->thirdPartyPayments as $payment): ?><tr><td class="label"><?= $e($payment->description) ?></td><td class="right"><?= $e($payment->amount) ?></td></tr><?php endforeach ?>
        <tr class="gap"><td></td><td></td></tr>
        <tr class="grand"><td class="label"><?= $e($totals->amountDueLabel) ?></td><td class="right amount"><?= $e($totals->amountDue) ?></td></tr>
    <?php else: ?>
        <?php /* Nothing prepaid or rounded: the amount due is the gross total, printed once. */ ?>
        <tr class="gap"><td></td><td></td></tr>
        <tr class="grand"><td class="label"><?= $e($labels->gross_total) ?></td><td class="right amount"><?= $e($totals->amountDue ?? $totals->grossTotal) ?></td></tr>
    <?php endif ?>
</table>
<?php if ($totals->taxCurrencyNote): ?><p class="small muted right"><?= $e($totals->taxCurrencyNote) ?></p><?php endif ?>
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
    <tr class="grand"><td><?= $e($labels->dunning_total) ?></td><td class="right amount"><?= $e($info->dunning->total) ?></td></tr>
</table>
<?php if ($info->dunning->deadlineText): ?><p><?= $e($info->dunning->deadlineText) ?></p><?php endif ?>
<?php endif ?>

<?php if ($info && $info->statement): ?>
<table class="totals">
    <tr><td><?= $e($labels->statement_period) ?></td><td class="right"><?= $e($info->statement->period) ?></td></tr>
    <?php if ($info->statement->openingBalance): ?><tr><td><?= $e($labels->opening_balance) ?></td><td class="right"><?= $e($info->statement->openingBalance) ?></td></tr><?php endif ?>
    <tr class="grand"><td><?= $e($labels->balance) ?></td><td class="right amount"><?= $e($info->statement->balance) ?></td></tr>
</table>
<?php endif ?>

<?php if (count($invoice->legalNotes) > 0): ?>
<div class="section">
    <?php foreach ($invoice->legalNotes as $note): ?><div><?= $e($note) ?></div><?php endforeach ?>
</div>
<?php endif ?>

<?php /* Credit notes and negative invoices are refunds: no bank details for a transfer by the buyer. */ ?>
<?php $accounts = $invoice->payment && ! $invoice->payment->isRefund && count($invoice->payment->accounts) > 0; ?>
<?php /* A direct debit collects from the buyer: not on refunds and not on payouts (self-billed). */ ?>
<?php $debit = $invoice->payment && $invoice->payment->directDebit && ! $invoice->payment->isRefund && ! $invoice->payment->isPayout; ?>
<?php $terms = $invoice->paymentTerms || $debit; ?>
<?php $qr = $invoice->payment ? $invoice->payment->qrCode : null; ?>
<?php if ($terms || $accounts): ?>
<div class="section">
    <?php /* Headings in a row of their own: mPDF ignores the padding of blocks inside table cells. */ ?>
    <table><tr>
        <td>
            <table class="payment">
                <tr>
                    <?php if ($terms): ?><td class="terms section-title"><?= $e($labels->payment) ?></td><?php endif ?>
                    <td class="section-title"><?php if ($accounts): ?><?= $e($invoice->payment->isPayout ? $labels->payout_account : $labels->bank_details) ?><?php endif ?></td>
                </tr>
                <tr>
                    <?php if ($terms): ?>
                    <td class="terms">
                        <?php if ($invoice->paymentTerms): ?>
                            <?php foreach ($invoice->paymentTerms->lines as $i => $line): ?><div<?= $i > 0 ? ' class="muted"' : '' ?>><?= $e($line) ?></div><?php endforeach ?>
                        <?php endif ?>
                        <?php if ($qr && $qr->note): ?><div class="muted"><?= $e($qr->note) ?></div><?php endif ?>
                        <?php if ($debit): ?>
                            <div><?= $e($labels->direct_debit) ?></div>
                            <?php if ($invoice->payment->directDebit->mandate || $invoice->payment->directDebit->creditorId): ?>
                            <div class="muted"><?php if ($invoice->payment->directDebit->mandate): ?><?= $e($labels->mandate) ?>: <?= $e($invoice->payment->directDebit->mandate) ?><?php endif ?><?php if ($invoice->payment->directDebit->creditorId): ?> · <?= $e($labels->creditor_id) ?>: <?= $e($invoice->payment->directDebit->creditorId) ?><?php endif ?></div>
                            <?php endif ?>
                        <?php endif ?>
                    </td>
                    <?php endif ?>
                    <td>
                        <?php if ($accounts): ?>
                            <?php foreach ($invoice->payment->accounts as $i => $account): ?>
                                <?php if ($i > 0): ?><div>&nbsp;</div><?php endif ?>
                                <?php if ($invoice->payee): ?><div><?= $e($labels->payee) ?>: <?= $e($invoice->payee->name) ?></div><?php endif ?>
                                <?php /* The account holder only if it is not the issuer named above. */ ?>
                                <?php if ($account->name && $account->name !== $invoice->issuer->name && $account->name !== $invoice->issuer->displayName): ?><div><?= $e($account->name) ?></div><?php endif ?>
                                <div><?= $e($account->iban) ?></div>
                                <div class="muted"><?php if ($account->bic): ?><?= $e($account->bic) ?> · <?php endif ?><?= $e($labels->remittance_short) ?>: <?= $e($invoice->payment->remittance) ?></div>
                            <?php endforeach ?>
                        <?php endif ?>
                    </td>
                </tr>
            </table>
        </td>
        <?php if ($qr): ?>
        <?php /* The note on a discounted amount is printed with the payment terms. */ ?>
        <td class="qr">
            <img src="<?= $e($qr->dataUri) ?>" style="width: 17mm; height: 17mm;">
            <div class="qr-caption"><?= $e($labels->girocode) ?></div>
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
