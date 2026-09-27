<?php
declare(strict_types=1);

namespace om\ICal\Exception;

use RuntimeException;

/**
 * Input or expansion exceeding the configured limits (file size, nesting, occurrences, ...).
 *
 * @phpstan-consistent-constructor
 */
class ResourceLimitException extends RuntimeException implements ICalException {
	use ErrorDetails;
}
