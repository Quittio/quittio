<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Exceptions\QuittioException;
use RuntimeException;

/**
 * A template omitted mandatory invoice information.
 */
final class MandatoryFieldMissing extends RuntimeException implements QuittioException
{
    /**
     * @param  list<MandatoryField>  $fields
     */
    public function __construct(string $message, public readonly array $fields)
    {
        parent::__construct($message);
    }
}
