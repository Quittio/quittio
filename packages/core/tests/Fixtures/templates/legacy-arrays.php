<?php
/**
 * A template as published before the typed view model: array syntax only, no types.
 * Must print exactly what typed-nodes.php prints (TypedViewModelTest).
 *
 * @var \Quittio\Core\View\InvoiceViewModel $invoice
 * @var callable(mixed): string $e
 */
$labels = $invoice['labels'];
?>
<h1 style="color: <?= $e($invoice['design']['primaryColor']) ?>"><?= $e($invoice['title']) ?> <?= $e($invoice['number']) ?></h1>
<p><?= $e($invoice['dateLabel']) ?> <?= $e($invoice['issueDate']) ?><?php if ($invoice['dueDate']): ?> · <?= $e($invoice['dueDate']->formatted) ?><?php endif ?></p>
<?php if ($invoice['servicePeriod']): ?><p><?= $e($invoice['servicePeriodLabel']) ?> <?= $e($invoice['servicePeriod']) ?></p><?php endif ?>
<p class="sender"><?= $e($invoice['design']['senderLine']) ?></p>
<?php foreach (['issuer', 'recipient'] as $role): ?>
<address><?= $e(implode(' / ', $invoice[$role]['address']['lines']->toArray())) ?><?php if ($invoice[$role]['vatId']): ?> · <?= $e($invoice[$role]['vatId']) ?><?php endif ?><?php if ($invoice[$role]['taxNumber']): ?> · <?= $e($invoice[$role]['taxNumber']) ?><?php endif ?></address>
<?php endforeach ?>
<?php if ($invoice['taxRepresentative']): ?><p><?= $e($invoice['taxRepresentative']['name']) ?> <?= $e($invoice['taxRepresentative']['vatId']) ?> <?= $e(implode(' / ', $invoice['taxRepresentative']['address']['lines']->toArray())) ?></p><?php endif ?>
<?php if ($invoice['delivery'] && $invoice['delivery']['date']): ?><p><?= $e($invoice['delivery']['date']) ?></p><?php endif ?>
<?php foreach ($invoice['references'] as $reference): ?><p><?= $e($reference['label']) ?>: <?= $e($reference['value']) ?></p><?php endforeach ?>
<?php foreach ($invoice['precedingInvoices'] as $preceding): ?><p><?= $e($preceding['number']) ?> <?= $e($preceding['issueDate']) ?></p><?php endforeach ?>
<table>
<?php foreach ($invoice['lineGroups'] as $group): ?>
    <?php if ($group['title']): ?><tr><td colspan="4"><?= $e($group['title']) ?></td></tr><?php endif ?>
    <?php foreach ($group['lines'] as $i => $line): ?>
    <tr data-i="<?= $e($i) ?>" data-carry-over="<?= $e($line['carryOver']) ?>">
        <td><?= $e($line['position']) ?></td>
        <td><?= $e($line['name']) ?><?php if ($line['description']['html']): ?><div><?= $line['description']['html'] ?></div><?php endif ?><?php if ($line['period']): ?> <?= $e($line['period']) ?><?php endif ?>
            <?php foreach ($line['allowances'] as $allowance): ?><div><?= $e($allowance['label']) ?> <?= $e($allowance['amount']) ?></div><?php endforeach ?>
            <?php if (count($line['serialNumbers']) > 0): ?><div><?= $e(implode(', ', $line['serialNumbers']->toArray())) ?></div><?php endif ?>
        </td>
        <td><?= $e($line['quantity']) ?> <?= $e($line['unit']['label']) ?></td>
        <td><?= $e($line['unitPrice']) ?></td>
        <td><?= $e($line['vatRate']) ?></td>
        <td><?= $e($line['total']) ?></td>
    </tr>
    <?php foreach ($line['subLines'] as $sub): ?>
    <tr class="sub"><td><?= $e($sub['position']) ?></td><td><?= $e($sub['name']) ?></td><td><?= $e($sub['quantity']) ?> <?= $e($sub['unit']['label']) ?></td><td><?= $e($sub['total']) ?></td></tr>
    <?php endforeach ?>
    <?php endforeach ?>
    <?php if ($group['subtotal']): ?><tr><td colspan="4"><?= $e($labels['subtotal']) ?> <?= $e($group['subtotal']) ?></td></tr><?php endif ?>
<?php endforeach ?>
</table>
<?php foreach ($invoice['allowances'] as $allowance): ?><p><?= $e($allowance['label']) ?> −<?= $e($allowance['amount']) ?></p><?php endforeach ?>
<?php foreach ($invoice['charges'] as $charge): ?><p><?= $e($charge['label']) ?> <?= $e($charge['amount']) ?></p><?php endforeach ?>
<?php if ($invoice['totals']): ?>
<table class="totals">
    <tr><td><?= $e($labels['net_total']) ?></td><td><?= $e($invoice['totals']['netTotal']) ?></td></tr>
    <?php foreach ($invoice['vatRows'] as $row): ?><tr><td><?= $e($row['label']) ?></td><td><?= $e($row['amount']) ?></td></tr><?php endforeach ?>
    <tr><td><?= $e($labels['gross_total']) ?></td><td><?= $e($invoice['totals']['grossTotal']) ?></td></tr>
    <?php if ($invoice['totals']['amountDue']): ?><tr><td><?= $e($invoice['totals']['amountDueLabel']) ?></td><td><?= $e($invoice['totals']['amountDue']) ?> (<?= $e($invoice['totals']['amountDue']->raw) ?>)</td></tr><?php endif ?>
</table>
<?php endif ?>
<?php foreach ($invoice['legalNotes'] as $note): ?><p class="legal"><?= $e($note) ?></p><?php endforeach ?>
<?php if ($invoice['paymentTerms']): ?><?php foreach ($invoice['paymentTerms']['lines'] as $text): ?><p><?= $e($text) ?></p><?php endforeach ?><?php endif ?>
<?php if ($invoice['payment']): ?>
<div class="payment"><?= $e($invoice['payment']['means']) ?> · <?= $e($invoice['payment']['remittance']) ?>
    <?php foreach ($invoice['payment']['accounts'] as $account): ?><div><?= $e($account['iban']) ?> <?= $e($account['bic']) ?></div><?php endforeach ?>
    <?php if ($invoice['payment']['qrCode']): ?><img src="<?= $e($invoice['payment']['qrCode']['dataUri']) ?>" alt="<?= $e($invoice['payment']['qrCode']['type']) ?>"><?php endif ?>
</div>
<?php endif ?>
<?php foreach ($invoice['notes'] as $note): ?><div><?= $note['html'] ?></div><?php endforeach ?>
<?php if ($invoice['documentInfo'] && $invoice['documentInfo']['dunning']): ?>
    <?php foreach ($invoice['documentInfo']['dunning']['openItems'] as $item): ?><p><?= $e($item['invoiceNumber']) ?> <?= $e($item['open']) ?></p><?php endforeach ?>
    <p><?= $e($invoice['documentInfo']['dunning']['total']) ?></p>
<?php endif ?>
<footer><?php foreach ($invoice['design']['footerColumns'] as $column): ?><div><?php foreach ($column as $entry): ?><?= $e($entry) ?>; <?php endforeach ?></div><?php endforeach ?></footer>
<p><?= $e(count($invoice['lines'])) ?> <?= $e(json_encode($invoice['meta']->toArray())) ?> <?= $e($invoice->label('continued_on', ['page' => '2'])) ?></p>
