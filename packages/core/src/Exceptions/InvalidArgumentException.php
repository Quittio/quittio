<?php

declare(strict_types=1);

namespace Quittio\Core\Exceptions;

/**
 * \InvalidArgumentException thrown by Quittio; catch QuittioException for every error of the library.
 */
class InvalidArgumentException extends \InvalidArgumentException implements QuittioException {}
