<?php

declare(strict_types=1);

namespace Quittio\Laravel\Contracts;

use Illuminate\Console\Command;

/**
 * Implemented by quittio/managed: renders a stored invoice again ("quittio:render {invoice}").
 */
interface ManagedInvoiceRenderer
{
    /**
     * @param  string  $invoice  number, UUID or id of the stored invoice
     * @return int command exit code
     */
    public function render(string $invoice, Command $command): int;
}
