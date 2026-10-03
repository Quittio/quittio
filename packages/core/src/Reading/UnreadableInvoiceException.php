<?php

declare(strict_types=1);

namespace Quittio\Core\Reading;

use Quittio\Core\Exceptions\QuittioException;
use RuntimeException;

/**
 * The input is not a supported e-invoice.
 */
final class UnreadableInvoiceException extends RuntimeException implements QuittioException {}
