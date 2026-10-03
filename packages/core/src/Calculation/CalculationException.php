<?php

declare(strict_types=1);

namespace Quittio\Core\Calculation;

use Quittio\Core\Exceptions\QuittioException;
use RuntimeException;

final class CalculationException extends RuntimeException implements QuittioException {}
