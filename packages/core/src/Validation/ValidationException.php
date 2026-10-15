<?php

declare(strict_types=1);

namespace Quittio\Core\Validation;

use Quittio\Core\Exceptions\QuittioException;
use RuntimeException;

/**
 * Thrown when an invoice does not pass validation. The full result is attached.
 */
final class ValidationException extends RuntimeException implements QuittioException
{
    public function __construct(
        public readonly ValidationResult $result,
    ) {
        $errors = $result->errors();
        $kind = 'error';
        if ($errors === []) {
            // Warnings block only when configured so (block_on_warnings).
            $errors = $result->warnings();
            $kind = 'blocking warning';
        }
        $first = array_slice($errors, 0, 5);
        // The hint says how to fix the finding, e.g. which configuration key to fill in.
        $lines = array_map(static fn (ValidationMessage $m): string => sprintf('[%s]%s %s', $m->code, $m->bt === null ? '' : ' '.$m->bt.':', $m->message)
            .($m->hint === null || $m->hint === '' ? '' : "\n    ".$m->hint), $first);
        $more = count($errors) > 5 ? sprintf("\n… and %d more.", count($errors) - 5) : '';

        parent::__construct(sprintf("The invoice is not valid (%d %s%s):\n%s%s", count($errors), $kind, count($errors) === 1 ? '' : 's', implode("\n", $lines), $more));
    }
}
