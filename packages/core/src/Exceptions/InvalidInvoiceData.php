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

    public static function invalidXmlCharacters(string $element): self
    {
        return new self(sprintf('The value of %s contains characters that XML 1.0 cannot represent (control characters or invalid UTF-8), see the rule QUITTIO-INVALID-CHARACTERS.', $element));
    }

    public static function duplicateLineId(string $id): self
    {
        return new self(sprintf('The line identifier "%s" (BT-126) is used more than once. Line identifiers must be unique, also across sub lines.', $id));
    }
}
