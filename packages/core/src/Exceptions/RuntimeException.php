<?php

declare(strict_types=1);

namespace Quittio\Core\Exceptions;

/**
 * \RuntimeException thrown by Quittio; catch QuittioException for every error of the library.
 */
class RuntimeException extends \RuntimeException implements QuittioException {}
