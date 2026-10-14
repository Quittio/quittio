<?php

declare(strict_types=1);

namespace Quittio\Core\Serialization\Snapshots;

use Quittio\Core\Exceptions\QuittioException;
use RuntimeException;

/**
 * A snapshot cannot be brought to the current version: an upcaster is missing, or the
 * snapshot was written by a newer version of the package.
 */
final class SnapshotVersionException extends RuntimeException implements QuittioException {}
