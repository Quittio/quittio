<?php
/**
 * The same output as legacy-arrays.php, written against the typed view model: object syntax,
 * and helpers whose parameters name the node classes (a wrong type would throw a TypeError).
 *
 * @var \Quittio\Core\View\InvoiceViewModel $invoice
 * @var callable(mixed): string $e
 */

use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\Model\AddressView;
use Quittio\Core\View\Model\LineView;
use Quittio\Core\View\Model\PartyView;
use Quittio\Core\View\Model\ViewList;

$labels = $invoice->labels;
$address = static fn (AddressView $address): string => implode(' / ', $address->lines->toArray());
$party = static function (PartyView $party) use ($e, $address): string {
    return $e($address($party->address)).($party->vatId ? ' · '.$e($party->vatId) : '').($party->taxNumber ? ' · '.$e($party->taxNumber) : '');
};
$amount = static fn (?FormattedValue $value): string => $e($value);
$position = static fn (LineView $line): string => $e($line->position);
/** @param ViewList<string> $list */
$join = static fn (ViewList $list): string => implode(', ', $list->toArray());
?>
<h1 style="color: <?= $e($invoice->design->primaryColor) ?>"><?= $e($invoice->title) ?> <?= $e($invoice->number) ?></h1>
<p><?= $e($invoice->dateLabel) ?> <?= $amount($invoice->issueDate) ?><?php if ($invoice->dueDate): ?> · <?= $e($invoice->dueDate->formatted) ?><?php endif ?></p>
<?php if ($invoice->servicePeriod): ?><p><?= $e($invoice->servicePeriodLabel) ?> <?= $amount($invoice->servicePeriod) ?></p><?php endif ?>
<p class="sender"><?= $e($invoice->design->senderLine) ?></p>
<?php foreach ([$invoice->issuer, $invoice->recipient] as $role): ?>
<address><?= $party($role) ?></address>
<?php endforeach ?>
<?php if ($invoice->taxRepresentative): ?><p><?= $e($invoice->taxRepresentative->name) ?> <?= $e($invoice->taxRepresentative->vatId) ?> <?= $e($address($invoice->taxRepresentative->address)) ?></p><?php endif ?>
<?php if ($invoice->delivery && $invoice->delivery->date): ?><p><?= $amount($invoice->delivery->date) ?></p><?php endif ?>
<?php foreach ($invoice->references as $reference): ?><p><?= $e($reference->label) ?>: <?= $e($reference->value) ?></p><?php endforeach ?>
<?php foreach ($invoice->precedingInvoices as $preceding): ?><p><?= $e($preceding->number) ?> <?= $amount($preceding->issueDate) ?></p><?php endforeach ?>
<table>
<?php foreach ($invoice->lineGroups as $group): ?>
    <?php if ($group->title): ?><tr><td colspan="4"><?= $e($group->title) ?></td></tr><?php endif ?>
    <?php foreach ($group->lines as $i => $line): ?>
    <tr data-i="<?= $e($i) ?>" data-carry-over="<?= $amount($line->carryOver) ?>">
        <td><?= $position($line) ?></td>
        <td><?= $e($line->name) ?><?php if ($line->description->html): ?><div><?= $line->description->html ?></div><?php endif ?><?php if ($line->period): ?> <?= $amount($line->period) ?><?php endif ?>
            <?php foreach ($line->allowances as $allowance): ?><div><?= $e($allowance->label) ?> <?= $amount($allowance->amount) ?></div><?php endforeach ?>
            <?php if (! $line->serialNumbers->isEmpty()): ?><div><?= $e($join($line->serialNumbers)) ?></div><?php endif ?>
        </td>
        <td><?= $amount($line->quantity) ?> <?= $e($line->unit->label) ?></td>
        <td><?= $amount($line->unitPrice) ?></td>
        <td><?= $amount($line->vatRate) ?></td>
        <td><?= $amount($line->total) ?></td>
    </tr>
    <?php foreach ($line->subLines as $sub): ?>
    <tr class="sub"><td><?= $e($sub->position) ?></td><td><?= $e($sub->name) ?></td><td><?= $amount($sub->quantity) ?> <?= $e($sub->unit->label) ?></td><td><?= $amount($sub->total) ?></td></tr>
    <?php endforeach ?>
    <?php endforeach ?>
    <?php if ($group->subtotal): ?><tr><td colspan="4"><?= $e($labels['subtotal']) ?> <?= $amount($group->subtotal) ?></td></tr><?php endif ?>
<?php endforeach ?>
</table>
<?php foreach ($invoice->allowances as $allowance): ?><p><?= $e($allowance->label) ?> −<?= $amount($allowance->amount) ?></p><?php endforeach ?>
<?php foreach ($invoice->charges as $charge): ?><p><?= $e($charge->label) ?> <?= $amount($charge->amount) ?></p><?php endforeach ?>
<?php if ($invoice->totals): ?>
<table class="totals">
    <tr><td><?= $e($labels['net_total']) ?></td><td><?= $amount($invoice->totals->netTotal) ?></td></tr>
    <?php foreach ($invoice->vatRows as $row): ?><tr><td><?= $e($row->label) ?></td><td><?= $amount($row->amount) ?></td></tr><?php endforeach ?>
    <tr><td><?= $e($labels['gross_total']) ?></td><td><?= $amount($invoice->totals->grossTotal) ?></td></tr>
    <?php if ($invoice->totals->amountDue): ?><tr><td><?= $e($invoice->totals->amountDueLabel) ?></td><td><?= $amount($invoice->totals->amountDue) ?> (<?= $e($invoice->totals->amountDue->raw) ?>)</td></tr><?php endif ?>
</table>
<?php endif ?>
<?php foreach ($invoice->legalNotes as $note): ?><p class="legal"><?= $e($note) ?></p><?php endforeach ?>
<?php if ($invoice->paymentTerms): ?><?php foreach ($invoice->paymentTerms->lines as $text): ?><p><?= $e($text) ?></p><?php endforeach ?><?php endif ?>
<?php if ($invoice->payment): ?>
<div class="payment"><?= $e($invoice->payment->means) ?> · <?= $e($invoice->payment->remittance) ?>
    <?php foreach ($invoice->payment->accounts as $account): ?><div><?= $e($account->iban) ?> <?= $e($account->bic) ?></div><?php endforeach ?>
    <?php if ($invoice->payment->qrCode): ?><img src="<?= $e($invoice->payment->qrCode->dataUri) ?>" alt="<?= $e($invoice->payment->qrCode->type) ?>"><?php endif ?>
</div>
<?php endif ?>
<?php foreach ($invoice->notes as $note): ?><div><?= $note->html ?></div><?php endforeach ?>
<?php if ($invoice->documentInfo && $invoice->documentInfo->dunning): ?>
    <?php foreach ($invoice->documentInfo->dunning->openItems as $item): ?><p><?= $e($item->invoiceNumber) ?> <?= $amount($item->open) ?></p><?php endforeach ?>
    <p><?= $amount($invoice->documentInfo->dunning->total) ?></p>
<?php endif ?>
<footer><?php foreach ($invoice->design->footerColumns as $column): ?><div><?php foreach ($column as $entry): ?><?= $e($entry) ?>; <?php endforeach ?></div><?php endforeach ?></footer>
<p><?= $e($invoice->lines->count()) ?> <?= $e(json_encode($invoice->meta->toArray())) ?> <?= $e($invoice->label('continued_on', ['page' => '2'])) ?></p>
