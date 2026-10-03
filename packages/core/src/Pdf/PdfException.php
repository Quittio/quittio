<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf;

use Quittio\Core\Exceptions\QuittioException;
use RuntimeException;

final class PdfException extends RuntimeException implements QuittioException {}
