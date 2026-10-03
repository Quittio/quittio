<?php

declare(strict_types=1);

namespace Quittio\Core\Exceptions;

use InvalidArgumentException;
use Throwable;

/**
 * Array or JSON input does not match the invoice data model.
 */
final class HydrationException extends InvalidArgumentException implements QuittioException
{
    public static function at(string $path, string $message, ?Throwable $previous = null): self
    {
        return new self(sprintf('%s: %s', $path === '' ? '(root)' : $path, $message), 0, $previous);
    }
}
