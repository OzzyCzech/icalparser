<?php
declare(strict_types=1);

namespace om\ICal\Exception;

use InvalidArgumentException;

/**
 * A property value that does not match its value type (DATE-TIME, DURATION, INTEGER, ...).
 *
 * @phpstan-consistent-constructor
 */
class InvalidValueException extends InvalidArgumentException implements ICalException {
	use ErrorDetails;
}
