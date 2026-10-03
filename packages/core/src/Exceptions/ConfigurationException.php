<?php

declare(strict_types=1);

namespace Quittio\Core\Exceptions;

use InvalidArgumentException;

/**
 * Invalid configuration or an unsupported combination of format and profile.
 */
final class ConfigurationException extends InvalidArgumentException implements QuittioException {}
