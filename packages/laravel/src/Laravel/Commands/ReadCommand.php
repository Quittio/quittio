<?php

declare(strict_types=1);

namespace Quittio\Laravel\Commands;

use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Support\Amount;
use Quittio\Laravel\QuittioManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'quittio:read')]
final class ReadCommand extends QuittioCommand
{
    protected $signature = 'quittio:read {file : XML or PDF file} {--json : Print the invoice data as JSON}';

    protected $description = 'Read an e-invoice file and show its content';

    public function handle(QuittioManager $quittio): int
    {
        $content = $this->readFile((string) $this->stringArgument('file'));
        if ($content === null) {
            return self::FAILURE;
        }

        try {
            $result = $quittio->read($content);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $invoice = $result->data;
        if ($this->flag('json')) {
            $this->line($invoice->toJson(JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Number', $invoice->number);
        $this->components->twoColumnDetail('Issue date', $invoice->issueDate->toIsoString());
        $this->components->twoColumnDetail('Type', $invoice->typeCodeValue());
        $this->components->twoColumnDetail('Syntax / profile', strtoupper($result->syntax).' / '.($result->profile ?? $result->specificationId ?? 'unknown'));
        $this->components->twoColumnDetail('Seller', $invoice->seller->name.' ('.$invoice->seller->address->countryCode.')');
        $this->components->twoColumnDetail('Buyer', $invoice->buyer->name.' ('.$invoice->buyer->address->countryCode.')');
        if ($result->embeddedFilename !== null) {
            $this->components->twoColumnDetail('Embedded file', $result->embeddedFilename);
        }

        $this->table(['Pos.', 'Item', 'Quantity', 'Net amount', 'VAT'], array_map(static fn (InvoiceLine $line): array => [
            $line->id,
            $line->item->name,
            $line->quantity->toString().' '.$line->unit->value,
            $line->netAmount === null ? '' : Amount::format($line->netAmount),
            $line->vat->category->value.' '.($line->vat->rate?->toString() ?? ''),
        ], $invoice->lines));

        $totals = $result->declaredTotals ?? $invoice->totals;
        if ($totals !== null) {
            $this->components->twoColumnDetail('Net total', Amount::format($totals->netTotal).' '.$invoice->currency);
            $this->components->twoColumnDetail('VAT', ($totals->vatTotal === null ? '0.00' : Amount::format($totals->vatTotal)).' '.$invoice->currency);
            $this->components->twoColumnDetail('Amount due', Amount::format($totals->amountDue).' '.$invoice->currency);
        }

        if ($result->findings !== []) {
            $this->newLine();
            $this->components->warn('Findings while reading:');
            foreach ($result->findings as $finding) {
                $this->line(sprintf('  [%s] %s', $finding->code, $finding->message));
            }
        }

        return self::SUCCESS;
    }
}
