<?php

declare(strict_types=1);

namespace Quittio\Core\Exceptions;

/**
 * \LogicException thrown by Quittio; catch QuittioException for every error of the library.
 */
class LogicException extends \LogicException implements QuittioException {}
