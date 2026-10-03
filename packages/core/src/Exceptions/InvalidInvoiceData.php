<?php

declare(strict_types=1);

namespace Quittio\Core\Exceptions;

use InvalidArgumentException;

/**
 * Builder or data input is structurally incomplete.
 */
final class InvalidInvoiceData extends InvalidArgumentException implements QuittioException
{
    public static function missing(string $bt, string $what): self
    {
        return new self(sprintf('Missing %s (%s).', $what, $bt));
    }
}
