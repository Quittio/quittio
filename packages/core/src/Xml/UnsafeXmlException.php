<?php

declare(strict_types=1);

namespace Quittio\Core\Xml;

use Quittio\Core\Exceptions\QuittioException;
use RuntimeException;

/**
 * Rejected XML input (DOCTYPE, too large, not well-formed).
 */
final class UnsafeXmlException extends RuntimeException implements QuittioException {}
